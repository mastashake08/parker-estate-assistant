<?php

namespace App\Services;

use App\Ai\Agents\LyricsComposer;
use App\Ai\Agents\MidiComposer;
use App\Enums\AudioGenerationStatus;
use App\Enums\AudioKind;
use App\Events\AudioGenerationUpdated;
use App\Jobs\GenerateAudio;
use App\Models\AudioGeneration;
use App\Models\User;
use App\Services\Audio\AudioServiceException;
use App\Services\Audio\DiffSingerClient;
use App\Services\Audio\DsScore;
use App\Services\Audio\MidiFile;
use App\Services\Audio\MusicGenClient;
use App\Services\Audio\PhononClient;
use App\Services\Audio\Wav;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Makes speech (Phonon), music (MusicGen), MIDI (Llama 3) and sung vocals
 * (Llama 3 lyrics sung by DiffSinger) for the audio studio, on the queue,
 * and keeps the files it produces.
 */
class AudioService
{
    public function __construct(
        protected PhononClient $phonon,
        protected MusicGenClient $musicGen,
        protected DiffSingerClient $diffSinger,
    ) {}

    /**
     * Get the user's most recent generations, newest first.
     *
     * @return Collection<int, AudioGeneration>
     */
    public function list(User $user): Collection
    {
        return $user->audioGenerations()
            ->latest()
            ->latest('id')
            ->limit(config()->integer('audio.library_size'))
            ->get();
    }

    /**
     * Record a generation and queue the work.
     *
     * @param  array{kind: string, prompt: string, server?: string|null, voice?: string|null, speaker?: string|null, model?: string|null, duration?: int|null, bars?: int|null, tempo?: int|null}  $attributes
     */
    public function queue(User $user, array $attributes): AudioGeneration
    {
        $kind = AudioKind::from($attributes['kind']);

        $options = match ($kind) {
            AudioKind::Speech => ['server' => $attributes['server'] ?? null, 'voice' => $attributes['voice'] ?? null],
            AudioKind::Music => ['duration' => (int) ($attributes['duration'] ?? 10)],
            AudioKind::Midi => ['bars' => (int) ($attributes['bars'] ?? 8), 'tempo' => $attributes['tempo'] ?? null],
            AudioKind::Vocals => [
                'voice' => $attributes['voice'] ?? null,
                'speaker' => $attributes['speaker'] ?? null,
                'bars' => (int) ($attributes['bars'] ?? 8),
                'tempo' => $attributes['tempo'] ?? null,
            ],
        };

        $generation = $user->audioGenerations()->create([
            'kind' => $kind,
            'status' => AudioGenerationStatus::Pending,
            'title' => Str::limit(Str::squish($attributes['prompt']), 60),
            'prompt' => $attributes['prompt'],
            'model' => match ($kind) {
                AudioKind::Speech => null,
                AudioKind::Music => ($attributes['model'] ?? null) ?: config('audio.music.model'),
                AudioKind::Midi, AudioKind::Vocals => ($attributes['model'] ?? null) ?: config('audio.midi.model'),
            },
            'options' => $options,
        ]);

        GenerateAudio::dispatch($generation);

        return $generation;
    }

    /**
     * Save music the user made in their browser as a finished generation.
     *
     * @param  array{prompt: string, duration: int, device?: string|null}  $attributes
     *
     * @throws AudioServiceException When the file is not a WAV.
     */
    public function storeBrowserMusic(User $user, array $attributes, UploadedFile $file): AudioGeneration
    {
        $wav = (string) $file->get();

        try {
            $duration = Wav::duration($wav);
        } catch (InvalidArgumentException) {
            throw new AudioServiceException('The music is not a WAV file.');
        }

        $generation = $user->audioGenerations()->make([
            'kind' => AudioKind::Music,
            'status' => AudioGenerationStatus::Completed,
            'title' => Str::limit(Str::squish($attributes['prompt']), 60),
            'prompt' => $attributes['prompt'],
            'model' => config('audio.music.browser_model'),
            'options' => [
                'duration' => (int) $attributes['duration'],
                'source' => 'browser',
                'device' => $attributes['device'] ?? null,
            ],
            'duration' => $duration,
            'finished_at' => now(),
        ]);

        $generation->fill($this->store($generation, $wav))->save();

        AudioGenerationUpdated::dispatch($generation);

        return $generation;
    }

    /**
     * Do the work for a queued generation and store its file.
     *
     * Failures are recorded on the generation with a message for the user,
     * and the browser is told when it starts, finishes or fails.
     */
    public function generate(AudioGeneration $generation): void
    {
        $this->transition($generation, ['status' => AudioGenerationStatus::Running]);

        try {
            $attributes = match ($generation->kind) {
                AudioKind::Speech => $this->speak($generation),
                AudioKind::Music => $this->compose($generation),
                AudioKind::Midi => $this->write($generation),
                AudioKind::Vocals => $this->sing($generation),
            };
        } catch (Throwable $exception) {
            report($exception);

            $this->fail($generation, $exception instanceof AudioServiceException
                ? $exception->getMessage()
                : 'The audio could not be made. Please try again.');

            return;
        }

        $this->transition($generation, [
            ...$attributes,
            'status' => AudioGenerationStatus::Completed,
            'finished_at' => now(),
        ]);
    }

    /**
     * Mark a generation failed, unless it already finished.
     */
    public function fail(AudioGeneration $generation, string $error): void
    {
        if ($generation->status === AudioGenerationStatus::Completed) {
            return;
        }

        $this->transition($generation, [
            'status' => AudioGenerationStatus::Failed,
            'error' => $error,
            'finished_at' => now(),
        ]);
    }

    /**
     * Delete a generation and its file.
     */
    public function delete(AudioGeneration $generation): void
    {
        Storage::disk(config('audio.disk'))->delete(array_filter([$generation->path, $generation->score_path]));

        $generation->delete();
    }

    /**
     * The generation's file, one stem of a MIDI generation as a file of its
     * own, or the DiffSinger score (.ds file) that vocals were sung from.
     *
     * The score is kept even when singing fails, so it can be opened in
     * another DiffSinger editor.
     *
     * @return array{contents: string, name: string, mime: string}|null Nothing when the file is not ready.
     */
    public function file(AudioGeneration $generation, ?int $stem = null, bool $score = false): ?array
    {
        $name = Str::slug($generation->title) ?: 'audio';

        if ($score) {
            $contents = $generation->score_path ? Storage::disk(config('audio.disk'))->get($generation->score_path) : null;

            return $contents === null ? null : ['contents' => $contents, 'name' => "{$name}.ds", 'mime' => 'application/json'];
        }

        if ($generation->status !== AudioGenerationStatus::Completed || ! $generation->path) {
            return null;
        }

        $extension = $generation->kind->extension();

        if ($stem !== null) {
            $track = $generation->stems[$stem] ?? null;

            if ($track === null) {
                return null;
            }

            return [
                'contents' => MidiFile::write([$track], (int) $generation->option('tempo', 120), (int) $generation->option('beats_per_bar', 4), $track['name']),
                'name' => $name.'-'.(Str::slug($track['name']) ?: 'stem-'.($stem + 1)).'.mid',
                'mime' => AudioKind::Midi->mimeType(),
            ];
        }

        $contents = Storage::disk(config('audio.disk'))->get($generation->path);

        return $contents === null ? null : [
            'contents' => $contents,
            'name' => "{$name}.{$extension}",
            'mime' => $generation->kind->mimeType(),
        ];
    }

    /**
     * Speak the prompt with Phonon.
     *
     * @return array<string, mixed>
     */
    protected function speak(AudioGeneration $generation): array
    {
        $server = (string) $generation->option('server');

        if (! in_array($server, config('audio.speech.servers'), true)) {
            throw new AudioServiceException('That speech server is no longer configured.');
        }

        $wav = $this->phonon->synthesize($server, $generation->prompt, $generation->option('voice'));

        return [
            ...$this->store($generation, $wav),
            'model' => $this->phonon->model($server),
            'duration' => Wav::duration($wav),
        ];
    }

    /**
     * Generate music with MusicGen.
     *
     * @return array<string, mixed>
     */
    protected function compose(AudioGeneration $generation): array
    {
        $wav = $this->musicGen->generate(
            $generation->prompt,
            (string) $generation->model,
            min((int) $generation->option('duration', 10), config()->integer('audio.music.max_seconds')),
        );

        try {
            $duration = Wav::duration($wav);
        } catch (InvalidArgumentException) {
            throw new AudioServiceException('MusicGen did not return a WAV file.');
        }

        return [...$this->store($generation, $wav), 'duration' => $duration];
    }

    /**
     * Compose MIDI stems with the language model and write them as a MIDI file.
     *
     * @return array<string, mixed>
     */
    protected function write(AudioGeneration $generation): array
    {
        $bars = (int) $generation->option('bars', 8);
        $beatsPerBar = 4;
        $tempo = $generation->option('tempo');

        $prompt = $generation->prompt
            ."\n\nWrite {$bars} bars in 4/4 time (".($bars * $beatsPerBar).' beats in all)'
            .($tempo ? " at {$tempo} BPM" : '').'.';

        try {
            $response = (new MidiComposer)->prompt(
                $prompt,
                provider: config('audio.midi.provider'),
                model: $generation->model,
                timeout: config()->integer('audio.midi.timeout'),
            );
        } catch (Throwable $exception) {
            throw new AudioServiceException("The language model ({$generation->model}) could not compose the MIDI. Check that it is installed in Ollama.", previous: $exception);
        }

        $composition = $this->normalize($response->toArray(), $bars * $beatsPerBar);

        if ($composition['stems'] === []) {
            throw new AudioServiceException('The language model did not write any notes. Try again or describe the music in more detail.');
        }

        $tempo = (int) ($tempo ?: $composition['tempo']);

        $midi = MidiFile::write($composition['stems'], $tempo, $beatsPerBar, $composition['title']);

        return [
            ...$this->store($generation, $midi),
            'title' => Str::limit($composition['title'] ?: $generation->title, 60),
            'stems' => $composition['stems'],
            'duration' => $bars * $beatsPerBar * 60 / $tempo,
            'options' => [
                ...$generation->options,
                'tempo' => $tempo,
                'beats_per_bar' => $beatsPerBar,
                'key' => $composition['key'],
            ],
        ];
    }

    /**
     * Write lyrics and their melody with the language model, write them as a
     * DiffSinger score in the voice's phonemes, and have DiffSinger sing it.
     *
     * @return array<string, mixed>
     */
    protected function sing(AudioGeneration $generation): array
    {
        $voice = $this->diffSinger->voice((string) $generation->option('voice'));
        $dictionary = $this->diffSinger->dictionary($voice['name'], $voice['language']);

        if ($dictionary['entries'] === [] || $dictionary['vowels'] === []) {
            throw new AudioServiceException("The DiffSinger voice {$voice['name']} has no phoneme dictionary, so it cannot be given lyrics.");
        }

        $bars = (int) $generation->option('bars', 8);
        $beatsPerBar = 4;
        $tempo = $generation->option('tempo');

        $prompt = $generation->prompt
            ."\n\nWrite about {$bars} bars in 4/4 time (".($bars * $beatsPerBar).' beats in all, rests included)'
            .($tempo ? " at {$tempo} BPM" : '').'.';

        try {
            $response = (new LyricsComposer($dictionary['language']))->prompt(
                $prompt,
                provider: config('audio.midi.provider'),
                model: $generation->model,
                timeout: config()->integer('audio.midi.timeout'),
            );
        } catch (Throwable $exception) {
            throw new AudioServiceException("The language model ({$generation->model}) could not write the lyrics. Check that it is installed in Ollama.", previous: $exception);
        }

        $song = $this->normalizeLyrics($response->toArray(), $bars * $beatsPerBar);
        $tempo = (int) ($tempo ?: $song['tempo']);

        $score = DsScore::build($song['lines'], $tempo, $dictionary, $dictionary['language']);

        if ($score['segments'] === []) {
            throw new AudioServiceException('The language model did not write any lyrics. Try again or describe the song in more detail.');
        }

        // Keep the score first, so it can be downloaded even if singing fails.
        $scorePath = "audio/{$generation->user_id}/".Str::uuid7().'.ds';
        Storage::disk(config('audio.disk'))->put($scorePath, json_encode($score['segments'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        $generation->update(['score_path' => $scorePath]);

        $wav = $this->diffSinger->render($voice['name'], $score['segments'], $generation->option('speaker') ?: null);

        try {
            $duration = Wav::duration($wav);
        } catch (InvalidArgumentException) {
            throw new AudioServiceException('DiffSinger did not return a WAV file.');
        }

        return [
            ...$this->store($generation, $wav),
            'title' => Str::limit($song['title'] ?: $generation->title, 60),
            'duration' => $duration,
            // The melody, so it can be played over MIDI alongside the singing (choir "oohs").
            'stems' => [['name' => 'Vocal melody', 'program' => 53, 'channel' => 0, 'drums' => false, 'notes' => $score['notes']]],
            'options' => [
                ...$generation->options,
                'tempo' => $tempo,
                'beats_per_bar' => $beatsPerBar,
                'key' => $song['key'],
                'language' => $dictionary['language'],
                'lyrics' => $score['lyrics'],
                'unknown_words' => $score['unknown'],
                // The audio starts with this many beats of silence before the first line.
                'lead_in_beats' => DsScore::LEAD_IN_BEATS,
            ],
        ];
    }

    /**
     * Turn the model's song into lines that are safe to sing: values clamped,
     * and lines past the length asked for dropped.
     *
     * @param  array<string, mixed>  $song
     * @return array{title: string, key: string, tempo: int, lines: list<array{notes: list<array{lyric: string, pitch: int, beats: float}>, rest: float}>}
     */
    public function normalizeLyrics(array $song, int $beats): array
    {
        $clamp = fn (mixed $value, int|float $min, int|float $max, int|float $default) => max($min, min($max, is_numeric($value) ? $value + 0 : $default));

        $lines = [];
        $used = 0.0;

        foreach (array_slice((array) ($song['lines'] ?? []), 0, config()->integer('audio.singing.max_lines')) as $line) {
            if (! is_array($line)) {
                continue;
            }

            $notes = [];

            foreach ((array) ($line['notes'] ?? []) as $note) {
                if (! is_array($note) || $used >= $beats) {
                    continue;
                }

                $length = round((float) $clamp($note['beats'] ?? 1, 0.25, 8, 1), 4);
                $used += $length;

                $notes[] = [
                    'lyric' => Str::limit(trim((string) ($note['lyric'] ?? '')), 40, ''),
                    'pitch' => (int) $clamp($note['pitch'] ?? 60, 36, 96, 60),
                    'beats' => $length,
                ];
            }

            if ($notes === []) {
                continue;
            }

            $rest = round((float) $clamp($line['rest'] ?? 1, 0, 8, 1), 4);
            $used += $rest;
            $lines[] = ['notes' => $notes, 'rest' => $rest];
        }

        return [
            'title' => Str::limit(trim((string) ($song['title'] ?? '')), 60),
            'key' => Str::limit(trim((string) ($song['key'] ?? '')), 30),
            'tempo' => (int) $clamp($song['tempo'] ?? 100, 50, 200, 100),
            'lines' => $lines,
        ];
    }

    /**
     * Turn the model's composition into stems that are safe to write and play:
     * values clamped to MIDI's ranges, notes past the end dropped, and each
     * stem given its own channel (drums on channel 10).
     *
     * @param  array<string, mixed>  $composition
     * @return array{title: string, key: string, tempo: int, stems: list<array{name: string, program: int, channel: int, drums: bool, notes: list<array{pitch: int, start: float, duration: float, velocity: int}>}>}
     */
    public function normalize(array $composition, int $beats): array
    {
        $clamp = fn (mixed $value, int|float $min, int|float $max) => max($min, min($max, is_numeric($value) ? $value + 0 : $min));

        $stems = [];
        $channels = array_values(array_diff(range(0, 15), [9]));

        foreach (array_slice((array) ($composition['tracks'] ?? []), 0, config()->integer('audio.midi.max_tracks')) as $index => $track) {
            if (! is_array($track)) {
                continue;
            }

            $notes = [];

            foreach ((array) ($track['notes'] ?? []) as $note) {
                if (! is_array($note) || ! is_numeric($note['start'] ?? null) || $note['start'] >= $beats) {
                    continue;
                }

                $start = round((float) $clamp($note['start'], 0, $beats), 4);

                $notes[] = [
                    'pitch' => (int) $clamp($note['pitch'] ?? 60, 0, 127),
                    'start' => $start,
                    'duration' => round((float) $clamp($note['duration'] ?? 1, 0.0625, $beats - $start), 4),
                    'velocity' => (int) $clamp($note['velocity'] ?? 80, 1, 127),
                ];
            }

            if ($notes === []) {
                continue;
            }

            usort($notes, fn (array $a, array $b) => [$a['start'], $a['pitch']] <=> [$b['start'], $b['pitch']]);

            $drums = (bool) ($track['drums'] ?? false);

            $stems[] = [
                'name' => Str::limit(trim((string) ($track['name'] ?? '')) ?: 'Track '.($index + 1), 40),
                'program' => (int) $clamp($track['instrument'] ?? 0, 0, 127),
                'channel' => $drums ? 9 : $channels[count(array_filter($stems, fn (array $stem) => ! $stem['drums'])) % count($channels)],
                'drums' => $drums,
                'notes' => array_slice($notes, 0, config()->integer('audio.midi.max_notes')),
            ];
        }

        return [
            'title' => Str::limit(trim((string) ($composition['title'] ?? '')), 60),
            'key' => Str::limit(trim((string) ($composition['key'] ?? '')), 30),
            'tempo' => (int) $clamp($composition['tempo'] ?? 120, 40, 220),
            'stems' => $stems,
        ];
    }

    /**
     * Save the file for a generation.
     *
     * @return array{path: string, size: int}
     */
    protected function store(AudioGeneration $generation, string $contents): array
    {
        $path = "audio/{$generation->user_id}/".Str::uuid7().'.'.$generation->kind->extension();

        Storage::disk(config('audio.disk'))->put($path, $contents);

        return ['path' => $path, 'size' => strlen($contents)];
    }

    /**
     * Update a generation and tell the user's browser.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function transition(AudioGeneration $generation, array $attributes): void
    {
        $generation->update($attributes);

        AudioGenerationUpdated::dispatch($generation);
    }
}
