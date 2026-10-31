<?php

namespace App\Services\Audio;

/**
 * Writes DiffSinger scores (.ds files) from sung lines: each note's lyric,
 * pitch and length in beats.
 *
 * A .ds file is a list of segments, one per sung line. Each segment holds
 * the line's words and their phonemes (ph_seq), how many phonemes belong
 * to each word (ph_num), the notes (note_seq, note_dur, note_slur), and
 * estimates of each phoneme's length (ph_dur) and of the pitch curve
 * (f0_seq), so an acoustic model can sing it as written. A variance model,
 * when the voice has one, predicts better timing and pitch from the notes.
 *
 * DiffSinger aligns each word's first vowel with the start of its note,
 * so the consonants before that vowel are sung at the end of the word
 * before. That is why every line starts with a rest ("SP"): the first
 * word's opening consonants belong to it.
 */
class DsScore
{
    /**
     * Seconds between the points of the pitch curve.
     */
    public const F0_TIMESTEP = 0.005;

    /**
     * Beats of silence before the song, so the first line has a rest to start on.
     */
    public const LEAD_IN_BEATS = 1;

    /**
     * The longest a consonant is held, in seconds.
     */
    protected const CONSONANT_SECONDS = 0.07;

    /**
     * Lyrics that continue the word before onto another note (a slur).
     */
    protected const SLURS = ['-', '~', '_', '+', '…', '...'];

    /**
     * Lyrics that mean a rest.
     */
    protected const RESTS = ['', 'sp', 'ap', 'rest', 'r'];

    protected const NOTE_NAMES = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];

    /**
     * Write the score.
     *
     * @param  list<array{notes: list<array{lyric: string, pitch: int, beats: float}>, rest: float}>  $lines  Each line's notes and the beats of rest after it.
     * @param  array{entries: array<string, list<string>>, vowels: list<string>}  $dictionary  The voice's words and their phonemes.
     * @return array{segments: list<array<string, string|float>>, notes: list<array{pitch: int, start: float, duration: float, velocity: int}>, lyrics: string, unknown: list<string>}
     */
    public static function build(array $lines, int $tempo, array $dictionary, ?string $language = null): array
    {
        $secondsPerBeat = 60 / max(1, $tempo);
        $vowels = array_flip($dictionary['vowels']);
        $lookup = self::lookup($dictionary['entries']);
        $fallback = self::fallback($lookup);

        $segments = [];
        $melody = [];
        $lyrics = [];
        $unknown = [];
        $beat = (float) self::LEAD_IN_BEATS;

        foreach ($lines as $line) {
            $words = [['phonemes' => ['SP'], 'notes' => [['pitch' => null, 'seconds' => self::LEAD_IN_BEATS * $secondsPerBeat]], 'text' => 'SP']];
            $start = $beat;
            $sung = [];

            foreach ($line['notes'] as $note) {
                $lyric = trim($note['lyric']);
                $seconds = $note['beats'] * $secondsPerBeat;
                $last = array_key_last($words);
                $slur = in_array($lyric, self::SLURS, true);
                // A slur with no sung word before it is a rest.
                $rest = in_array(mb_strtolower($lyric), self::RESTS, true) || ($slur && $words[$last]['phonemes'] === ['SP']);

                if ($rest) {
                    $words[] = ['phonemes' => ['SP'], 'notes' => [['pitch' => null, 'seconds' => $seconds]], 'text' => 'SP'];
                } elseif ($slur) {
                    $words[$last]['notes'][] = ['pitch' => $note['pitch'], 'seconds' => $seconds];
                } else {
                    $phonemes = self::phonemes($lyric, $lookup);

                    if ($phonemes === null) {
                        $unknown[] = $lyric;
                        $phonemes = $fallback;
                    }

                    $words[] = ['phonemes' => $phonemes, 'notes' => [['pitch' => $note['pitch'], 'seconds' => $seconds]], 'text' => $lyric];
                    $sung[] = $lyric;
                }

                if (! $rest) {
                    $melody[] = ['pitch' => $note['pitch'], 'start' => round($beat, 4), 'duration' => round($note['beats'], 4), 'velocity' => 90];
                }

                $beat += $note['beats'];
            }

            if ($sung === []) {
                $beat += $line['rest'];

                continue;
            }

            // A closing rest gives the last word's final consonants somewhere to end.
            $words[] = ['phonemes' => ['SP'], 'notes' => [['pitch' => null, 'seconds' => max(0.1, min(0.5, $line['rest'] * $secondsPerBeat))]], 'text' => 'SP'];

            $segments[] = self::segment($words, $vowels, ($start - self::LEAD_IN_BEATS) * $secondsPerBeat, $language);
            $lyrics[] = implode(' ', $sung);
            $beat += $line['rest'];
        }

        return [
            'segments' => $segments,
            'notes' => $melody,
            'lyrics' => implode("\n", $lyrics),
            'unknown' => array_values(array_unique($unknown)),
        ];
    }

    /**
     * One line as a segment.
     *
     * @param  list<array{phonemes: list<string>, notes: list<array{pitch: int|null, seconds: float}>, text: string}>  $words
     * @param  array<string, int>  $vowels
     * @return array<string, string|float>
     */
    protected static function segment(array $words, array $vowels, float $offset, ?string $language): array
    {
        // Split each word at its first vowel: the consonants before it move to the word before.
        $split = array_map(function (array $word) use ($vowels) {
            $first = 0;

            foreach ($word['phonemes'] as $index => $phoneme) {
                if (isset($vowels[$phoneme]) || $phoneme === 'SP' || $phoneme === 'AP') {
                    $first = $index;
                    break;
                }
            }

            return [array_slice($word['phonemes'], 0, $first), array_slice($word['phonemes'], $first)];
        }, $words);

        $phonemes = [];
        $durations = [];
        $counts = [];

        foreach ($words as $index => $word) {
            $group = [...$split[$index][1], ...($split[$index + 1][0] ?? [])];
            $seconds = array_sum(array_column($word['notes'], 'seconds'));

            // The vowel takes the note; the consonants after it share a short time at the end.
            $consonants = count($group) - 1;
            $each = $consonants > 0 ? min(self::CONSONANT_SECONDS, $seconds * 0.4 / $consonants) : 0;

            $phonemes = [...$phonemes, ...$group];
            $durations = [...$durations, $seconds - $consonants * $each, ...array_fill(0, $consonants, $each)];
            $counts[] = count($group);
        }

        $notes = array_merge(...array_map(fn (array $word) => array_map(
            fn (array $note, int $index) => [...$note, 'slur' => $index > 0 ? 1 : 0],
            $word['notes'],
            array_keys($word['notes']),
        ), $words));

        $segment = [
            'offset' => round($offset, 4),
            'text' => implode(' ', array_column($words, 'text')),
            'ph_seq' => implode(' ', $phonemes),
            'ph_dur' => self::numbers($durations),
            'ph_num' => implode(' ', $counts),
            'note_seq' => implode(' ', array_map(fn (array $note) => $note['pitch'] === null ? 'rest' : self::noteName($note['pitch']), $notes)),
            'note_dur' => self::numbers(array_column($notes, 'seconds')),
            'note_slur' => implode(' ', array_column($notes, 'slur')),
            'f0_seq' => self::pitchCurve($notes),
            'f0_timestep' => (string) self::F0_TIMESTEP,
        ];

        if ($language !== null) {
            $segment = ['lang' => $language, ...$segment];
        }

        return $segment;
    }

    /**
     * Each note's pitch, held for its length, in hertz; rests take the pitch of the nearest note.
     *
     * @param  list<array{pitch: int|null, seconds: float}>  $notes
     */
    protected static function pitchCurve(array $notes): string
    {
        $pitches = array_values(array_filter(array_column($notes, 'pitch'), fn (?int $pitch) => $pitch !== null));
        $previous = $pitches[0] ?? 60;
        $frames = [];
        $elapsed = 0.0;

        foreach ($notes as $index => $note) {
            $pitch = $note['pitch'] ?? self::nextPitch($notes, $index) ?? $previous;
            $end = (int) round(($elapsed + $note['seconds']) / self::F0_TIMESTEP);
            $hertz = round(440 * 2 ** (($pitch - 69) / 12), 1);

            while (count($frames) < $end) {
                $frames[] = $hertz;
            }

            $elapsed += $note['seconds'];
            $previous = $note['pitch'] ?? $previous;
        }

        return implode(' ', $frames);
    }

    /**
     * The pitch of the first note after a rest, for the rest before a phrase.
     *
     * @param  list<array{pitch: int|null, seconds: float}>  $notes
     */
    protected static function nextPitch(array $notes, int $index): ?int
    {
        foreach (array_slice($notes, $index + 1) as $note) {
            if ($note['pitch'] !== null) {
                return $note['pitch'];
            }
        }

        return null;
    }

    /**
     * Look a lyric up, ignoring case and punctuation.
     *
     * @param  array<string, list<string>>  $lookup
     * @return list<string>|null
     */
    protected static function phonemes(string $lyric, array $lookup): ?array
    {
        $key = mb_strtolower($lyric);

        foreach ([$key, preg_replace("/[^\p{L}\p{N}']+/u", '', $key), preg_replace("/[^\p{L}\p{N}]+/u", '', $key)] as $candidate) {
            if ($candidate !== null && $candidate !== '' && isset($lookup[$candidate])) {
                return $lookup[$candidate];
            }
        }

        return null;
    }

    /**
     * The dictionary keyed by lowercase word.
     *
     * @param  array<string, list<string>>  $entries
     * @return array<string, list<string>>
     */
    protected static function lookup(array $entries): array
    {
        $lookup = [];

        foreach ($entries as $word => $phonemes) {
            $lookup[mb_strtolower((string) $word)] ??= array_values($phonemes);
        }

        return $lookup;
    }

    /**
     * What to sing for a word the voice does not know: an open vowel if it has one.
     *
     * @param  array<string, list<string>>  $lookup
     * @return list<string>
     */
    protected static function fallback(array $lookup): array
    {
        foreach (['a', 'ah', 'aa', 'la', 'o'] as $word) {
            if (isset($lookup[$word])) {
                return $lookup[$word];
            }
        }

        return ['SP'];
    }

    /**
     * A MIDI note number as a note name, such as 60 as "C4".
     */
    public static function noteName(int $pitch): string
    {
        return self::NOTE_NAMES[$pitch % 12].(intdiv($pitch, 12) - 1);
    }

    /**
     * Seconds as a space-separated list.
     *
     * @param  list<float|int>  $values
     */
    protected static function numbers(array $values): string
    {
        return implode(' ', array_map(fn (float|int $value) => (string) round($value, 4), $values));
    }
}
