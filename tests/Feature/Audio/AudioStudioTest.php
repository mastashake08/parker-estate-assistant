<?php

use App\Ai\Agents\LyricsComposer;
use App\Ai\Agents\MidiComposer;
use App\Enums\AudioGenerationStatus;
use App\Enums\AudioKind;
use App\Events\AudioGenerationUpdated;
use App\Jobs\GenerateAudio;
use App\Models\AudioGeneration;
use App\Models\User;
use App\Services\Audio\Wav;
use App\Services\AudioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Prompts\AgentPrompt;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Event::fake([AudioGenerationUpdated::class]);

    config([
        'audio.speech.servers' => ['http://phonon:8880'],
        'audio.music.url' => 'http://musicgen:8000',
        'audio.singing.url' => 'http://diffsinger:8000',
    ]);
});

/**
 * Fake every engine as online, with any responses replaced.
 *
 * @param  array<string, mixed>  $overrides
 */
function fakeEngines(array $overrides = []): void
{
    $engines = [
        'phonon:8880/v1/models' => Http::response(['object' => 'list', 'data' => [['id' => 'phonon-31m-en', 'languages' => ['en']]]]),
        'phonon:8880/v1/audio/voices' => Http::response(['voices' => ['Toby', 'Ava'], 'aliases' => []]),
        'phonon:8880/v1/audio/speech' => Http::response(str_repeat("\0\0", 24000)),
        'musicgen:8000/models' => Http::response(['loaded' => 'facebook/musicgen-small', 'loading' => null, 'device' => 'cpu', 'error' => null]),
        'musicgen:8000/models/load' => Http::response(['loaded' => null, 'loading' => 'facebook/musicgen-medium'], 202),
        'musicgen:8000/generate' => Http::response(Wav::fromPcm16(str_repeat("\0\0", 32000 * 2), 32000)),
        'diffsinger:8000/voices' => Http::response(['voices' => [['name' => 'Alto', 'language' => 'en', 'languages' => ['en'], 'speakers' => [], 'speaker' => null, 'variance' => true]]]),
        'diffsinger:8000/voices/Alto/dictionary*' => Http::response([
            'language' => 'en',
            'entries' => ['home' => ['hh', 'ow', 'm'], 'again' => ['ah', 'g', 'eh', 'n'], 'ah' => ['aa']],
            'vowels' => ['aa', 'ah', 'eh', 'ow'],
        ]),
        'diffsinger:8000/render' => Http::response(Wav::fromPcm16(str_repeat("\0\0", 48000), 24000)),
        '*/api/tags' => Http::response(['models' => [['name' => 'llama3:latest']]]),
    ];

    // Overrides come first, so they match before the engines' wildcards.
    Http::fake([...$overrides, ...array_diff_key($engines, $overrides)]);
}

function generation(string $kind, array $attributes = []): AudioGeneration
{
    return app(AudioService::class)->queue(User::factory()->create(), ['kind' => $kind, 'prompt' => 'Something lovely', ...$attributes]);
}

test('guests cannot open the studio', function () {
    $this->get(route('audio.index'))->assertRedirect(route('login'));
});

test('the studio lists only the user\'s own generations, and the engines after the page loads', function () {
    fakeEngines();
    $user = User::factory()->create();
    AudioGeneration::factory()->for($user)->create(['title' => 'mine']);
    AudioGeneration::factory()->create(['title' => 'theirs']);

    $this->actingAs($user)
        ->get(route('audio.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('audio/Studio')
            ->has('generations', 1)
            ->where('generations.0.title', 'mine')
            ->missing('generations.0.path')
            ->missing('speech')
            ->loadDeferredProps('engines', fn (Assert $reload) => $reload
                ->where('speech.0.model', 'phonon-31m-en')
                ->where('speech.0.voices', ['Toby', 'Ava'])
                ->where('music.loaded', 'facebook/musicgen-small')
                ->where('singing.voices.0.name', 'Alto')
                ->where('midiModels', ['llama3:latest'])
            )
        );
});

test('engines that are down are reported, not fatal', function () {
    Http::fake(['*' => Http::failedConnection()]);

    $this->actingAs(User::factory()->create())
        ->get(route('audio.index'))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('engines', fn (Assert $reload) => $reload
            ->where('speech.0.online', false)
            ->where('music.online', false)
            ->where('singing.online', false)
        ));
});

test('generating queues the work', function () {
    Queue::fake();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('audio.generations.store'), ['kind' => 'music', 'prompt' => 'A lo-fi beat', 'duration' => 12])
        ->assertRedirect(route('audio.index'));

    $generation = AudioGeneration::sole();

    expect($generation->user_id)->toBe($user->id)
        ->and($generation->kind)->toBe(AudioKind::Music)
        ->and($generation->status)->toBe(AudioGenerationStatus::Pending)
        ->and($generation->model)->toBe('facebook/musicgen-small')
        ->and($generation->options)->toBe(['duration' => 12]);

    Queue::assertPushed(GenerateAudio::class, fn (GenerateAudio $job) => $job->generation->is($generation));
});

test('generations are validated for their kind', function (array $input, string $field) {
    Queue::fake();

    $this->actingAs(User::factory()->create())
        ->post(route('audio.generations.store'), ['prompt' => 'Hello', ...$input])
        ->assertSessionHasErrors($field);

    Queue::assertNothingPushed();
})->with([
    'unknown kind' => [['kind' => 'video'], 'kind'],
    'speech without a server' => [['kind' => 'speech'], 'server'],
    'speech on an unconfigured server' => [['kind' => 'speech', 'server' => 'http://evil.test'], 'server'],
    'music with an unknown model' => [['kind' => 'music', 'duration' => 5, 'model' => 'someone/else'], 'model'],
    'music that is too long' => [['kind' => 'music', 'duration' => 600], 'duration'],
    'midi without bars' => [['kind' => 'midi'], 'bars'],
    'too many bars' => [['kind' => 'midi', 'bars' => 500], 'bars'],
    'vocals without a voice' => [['kind' => 'vocals', 'bars' => 8], 'voice'],
]);

test('speech is spoken by the chosen phonon server and stored as a wav', function () {
    fakeEngines();

    $generation = generation('speech', ['prompt' => 'Welcome home.', 'server' => 'http://phonon:8880', 'voice' => 'Ava'])->refresh();

    expect($generation->status)->toBe(AudioGenerationStatus::Completed)
        ->and($generation->model)->toBe('phonon-31m-en')
        ->and($generation->duration)->toBe(1.0)
        ->and(Storage::disk('local')->get($generation->path))->toStartWith('RIFF');

    Http::assertSent(fn (Request $request) => $request->url() === 'http://phonon:8880/v1/audio/speech'
        && $request['input'] === 'Welcome home.'
        && $request['voice'] === 'Ava'
        && $request['response_format'] === 'pcm');

    Event::assertDispatched(AudioGenerationUpdated::class, fn (AudioGenerationUpdated $event) => $event->generation->is($generation)
        && $event->broadcastWith()['status'] === 'completed');
});

test('music is generated by musicgen with the chosen model and length', function () {
    fakeEngines();

    $generation = generation('music', ['model' => 'facebook/musicgen-medium', 'duration' => 2])->refresh();

    expect($generation->status)->toBe(AudioGenerationStatus::Completed)
        ->and($generation->duration)->toBe(2.0);

    Http::assertSent(fn (Request $request) => $request->url() === 'http://musicgen:8000/generate'
        && $request['model'] === 'facebook/musicgen-medium'
        && $request['duration'] === 2);
});

test('an engine\'s error is shown on the generation', function () {
    fakeEngines(['musicgen:8000/generate' => Http::response(['detail' => 'Generation failed: out of memory'], 500)]);

    $generation = generation('music', ['duration' => 5])->refresh();

    expect($generation->status)->toBe(AudioGenerationStatus::Failed)
        ->and($generation->error)->toBe('MusicGen could not generate the music: Generation failed: out of memory')
        ->and($generation->path)->toBeNull();
});

test('midi is composed by the language model and written as stems', function () {
    MidiComposer::fake([[
        'title' => 'Night Drive',
        'key' => 'A minor',
        'tempo' => 300,
        'tracks' => [
            ['name' => 'Bass', 'instrument' => 38, 'drums' => false, 'notes' => [
                ['pitch' => 33, 'start' => 0, 'duration' => 2, 'velocity' => 100],
                ['pitch' => 200, 'start' => 2, 'duration' => 99, 'velocity' => 0],
                ['pitch' => 36, 'start' => 40, 'duration' => 1, 'velocity' => 90],
            ]],
            ['name' => 'Drums', 'instrument' => 0, 'drums' => true, 'notes' => [['pitch' => 36, 'start' => 0, 'duration' => 0.25, 'velocity' => 120]]],
            ['name' => 'Keys', 'instrument' => 4, 'drums' => false, 'notes' => [['pitch' => 60, 'start' => 1, 'duration' => 1, 'velocity' => 70]]],
            ['name' => 'Empty', 'instrument' => 0, 'drums' => false, 'notes' => []],
        ],
    ]]);

    $generation = generation('midi', ['bars' => 4])->refresh();

    expect($generation->status)->toBe(AudioGenerationStatus::Completed)
        ->and($generation->title)->toBe('Night Drive')
        ->and($generation->option('tempo'))->toBe(220)
        ->and($generation->option('key'))->toBe('A minor')
        ->and(Storage::disk('local')->get($generation->path))->toStartWith('MThd')
        ->and(array_column($generation->stems, 'name'))->toBe(['Bass', 'Drums', 'Keys'])
        // Drums play on channel 10; the others get channels of their own.
        ->and(array_column($generation->stems, 'channel'))->toBe([0, 9, 1])
        // Notes are clamped to MIDI's ranges and to the 16 beats asked for.
        ->and($generation->stems[0]['notes'])->toEqual([
            ['pitch' => 33, 'start' => 0.0, 'duration' => 2.0, 'velocity' => 100],
            ['pitch' => 127, 'start' => 2.0, 'duration' => 14.0, 'velocity' => 1],
        ]);

    MidiComposer::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, '4 bars in 4/4 time (16 beats in all)'));
});

test('the tempo asked for wins over the model\'s', function () {
    MidiComposer::fake([['title' => 'Waltz', 'key' => 'C', 'tempo' => 90, 'tracks' => [
        ['name' => 'Piano', 'instrument' => 0, 'drums' => false, 'notes' => [['pitch' => 60, 'start' => 0, 'duration' => 1, 'velocity' => 80]]],
    ]]]);

    $generation = generation('midi', ['bars' => 2, 'tempo' => 140])->refresh();

    expect($generation->option('tempo'))->toBe(140)
        ->and($generation->duration)->toEqualWithDelta(8 * 60 / 140, 0.0001);
});

test('a composition without notes fails with a message', function () {
    MidiComposer::fake([['title' => 'Silence', 'key' => 'C', 'tempo' => 120, 'tracks' => []]]);

    $generation = generation('midi', ['bars' => 2])->refresh();

    expect($generation->status)->toBe(AudioGenerationStatus::Failed)
        ->and($generation->error)->toContain('did not write any notes');
});

test('vocals are written by the language model, scored as a .ds file and sung by diffsinger', function () {
    fakeEngines();
    LyricsComposer::fake([[
        'title' => 'Home Again',
        'key' => 'C major',
        'tempo' => 120,
        'lines' => [
            ['notes' => [['lyric' => 'home', 'pitch' => 64, 'beats' => 2], ['lyric' => '-', 'pitch' => 62, 'beats' => 1], ['lyric' => 'again', 'pitch' => 60, 'beats' => 1]], 'rest' => 2],
        ],
    ]]);

    $generation = generation('vocals', ['voice' => 'Alto', 'bars' => 4])->refresh();

    expect($generation->status)->toBe(AudioGenerationStatus::Completed)
        ->and($generation->title)->toBe('Home Again')
        ->and($generation->option('lyrics'))->toBe('home again')
        ->and($generation->option('language'))->toBe('en')
        ->and($generation->duration)->toBe(2.0)
        ->and($generation->stems[0]['notes'])->toHaveCount(3)
        ->and(Storage::disk('local')->get($generation->path))->toStartWith('RIFF');

    $score = json_decode(Storage::disk('local')->get($generation->score_path), true);

    expect($score)->toHaveCount(1)
        ->and($score[0]['ph_seq'])->toBe('SP hh ow m ah g eh n SP')
        ->and($score[0]['ph_num'])->toBe('2 2 4 1')
        ->and($score[0]['note_seq'])->toBe('rest E4 D4 C4 rest')
        ->and($score[0]['note_slur'])->toBe('0 0 1 0 0');

    Http::assertSent(fn (Request $request) => $request->url() === 'http://diffsinger:8000/render'
        && $request['voice'] === 'Alto'
        && $request['ds'] === $score);

    LyricsComposer::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, 'about 4 bars'));
});

test('the score is kept when singing fails, so it can still be downloaded', function () {
    fakeEngines(['diffsinger:8000/render' => Http::response(['detail' => 'DiffSinger could not sing the score: Vocoder ckpt not found.'], 500)]);
    LyricsComposer::fake([['title' => 'Home', 'key' => 'C', 'tempo' => 100, 'lines' => [
        ['notes' => [['lyric' => 'home', 'pitch' => 60, 'beats' => 2]], 'rest' => 1],
    ]]]);

    $generation = generation('vocals', ['voice' => 'Alto', 'bars' => 2])->refresh();

    expect($generation->status)->toBe(AudioGenerationStatus::Failed)
        ->and($generation->error)->toContain('Vocoder ckpt not found.')
        ->and($generation->score_path)->not->toBeNull();

    $this->actingAs($generation->user)
        ->get(route('audio.generations.show', ['generation' => $generation, 'format' => 'ds']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json');
});

test('vocals need a voice that is installed', function () {
    fakeEngines();

    $generation = generation('vocals', ['voice' => 'Bass', 'bars' => 2])->refresh();

    expect($generation->status)->toBe(AudioGenerationStatus::Failed)
        ->and($generation->error)->toBe('The DiffSinger voice Bass is not installed.');
});

test('a job that fails outright marks its generation failed', function () {
    $generation = AudioGeneration::factory()->create(['status' => AudioGenerationStatus::Running]);

    (new GenerateAudio($generation))->failed(new RuntimeException('Timed out'));

    expect($generation->refresh()->status)->toBe(AudioGenerationStatus::Failed)
        ->and($generation->error)->toContain('took too long');
});

test('owners can play and download their files', function () {
    Storage::disk('local')->put('audio/1/song.wav', 'RIFF....WAVE');
    $generation = AudioGeneration::factory()->completed('audio/1/song.wav')->create(['title' => 'Lo-fi beat']);

    $this->actingAs($generation->user)
        ->get(route('audio.generations.show', $generation))
        ->assertOk()
        ->assertHeader('Content-Type', 'audio/wav')
        ->assertHeader('Content-Disposition', 'inline; filename="lo-fi-beat.wav"');

    $this->actingAs($generation->user)
        ->get(route('audio.generations.show', ['generation' => $generation, 'download' => 1]))
        ->assertHeader('Content-Disposition', 'attachment; filename="lo-fi-beat.wav"');
});

test('one stem of a midi generation can be downloaded on its own', function () {
    Storage::disk('local')->put('audio/1/song.mid', 'MThd');
    $generation = AudioGeneration::factory()->midi()->completed('audio/1/song.mid')->create([
        'title' => 'Groove',
        'options' => ['bars' => 4, 'tempo' => 100, 'beats_per_bar' => 4],
        'stems' => [['name' => 'Bass Line', 'program' => 33, 'channel' => 0, 'drums' => false, 'notes' => [['pitch' => 40, 'start' => 0, 'duration' => 1, 'velocity' => 90]]]],
    ]);

    $response = $this->actingAs($generation->user)
        ->get(route('audio.generations.show', ['generation' => $generation, 'stem' => 0]))
        ->assertOk()
        ->assertHeader('Content-Type', 'audio/midi')
        ->assertHeader('Content-Disposition', 'inline; filename="groove-bass-line.mid"');

    expect($response->getContent())->toStartWith('MThd');

    $this->actingAs($generation->user)
        ->get(route('audio.generations.show', ['generation' => $generation, 'stem' => 5]))
        ->assertNotFound();
});

test('files are hidden from other users and until they are ready', function () {
    Storage::disk('local')->put('audio/1/song.wav', 'RIFF');
    $ready = AudioGeneration::factory()->completed('audio/1/song.wav')->create();
    $pending = AudioGeneration::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('audio.generations.show', $ready))
        ->assertNotFound();

    $this->actingAs($pending->user)
        ->get(route('audio.generations.show', $pending))
        ->assertNotFound();
});

test('deleting a generation removes its files', function () {
    Storage::disk('local')->put('audio/1/song.wav', 'RIFF');
    Storage::disk('local')->put('audio/1/song.ds', '[]');
    $generation = AudioGeneration::factory()->completed('audio/1/song.wav')->create(['score_path' => 'audio/1/song.ds']);

    $this->actingAs(User::factory()->create())
        ->delete(route('audio.generations.destroy', $generation))
        ->assertNotFound();

    $this->actingAs($generation->user)
        ->delete(route('audio.generations.destroy', $generation))
        ->assertRedirect(route('audio.index'));

    expect(AudioGeneration::count())->toBe(0);
    Storage::disk('local')->assertMissing(['audio/1/song.wav', 'audio/1/song.ds']);
});

test('a musicgen model can be loaded', function () {
    fakeEngines();

    $this->actingAs(User::factory()->create())
        ->post(route('audio.music-models.store'), ['model' => 'facebook/musicgen-medium'])
        ->assertRedirect(route('audio.index'))
        ->assertInertiaFlash('toast.type', 'success');

    Http::assertSent(fn (Request $request) => $request->url() === 'http://musicgen:8000/models/load'
        && $request['model'] === 'facebook/musicgen-medium');
});

test('only the configured musicgen models can be loaded', function () {
    Http::fake();

    $this->actingAs(User::factory()->create())
        ->post(route('audio.music-models.store'), ['model' => 'someone/else'])
        ->assertSessionHasErrors('model');

    Http::assertNothingSent();
});

test('music made in the browser is saved to the library', function () {
    $user = User::factory()->create();
    $wav = UploadedFile::fake()->createWithContent('music.wav', Wav::fromPcm16(str_repeat("\0\0", 32000 * 3), 32000));

    $this->actingAs($user)
        ->post(route('audio.generations.browser.store'), ['prompt' => 'A lo-fi beat', 'duration' => 3, 'device' => 'webgpu', 'audio' => $wav])
        ->assertRedirect(route('audio.index'))
        ->assertInertiaFlash('toast.type', 'success');

    $generation = AudioGeneration::sole();

    expect($generation->user_id)->toBe($user->id)
        ->and($generation->kind)->toBe(AudioKind::Music)
        ->and($generation->status)->toBe(AudioGenerationStatus::Completed)
        ->and($generation->model)->toBe('Xenova/musicgen-small')
        ->and($generation->duration)->toBe(3.0)
        ->and($generation->options)->toBe(['duration' => 3, 'source' => 'browser', 'device' => 'webgpu'])
        ->and(Storage::disk('local')->get($generation->path))->toStartWith('RIFF');

    Event::assertDispatched(AudioGenerationUpdated::class);
});

test('browser uploads must be wav files within the limits', function (array $input, string $field) {
    $wav = UploadedFile::fake()->createWithContent('music.wav', Wav::fromPcm16(str_repeat("\0\0", 3200), 32000));

    $this->actingAs(User::factory()->create())
        ->post(route('audio.generations.browser.store'), ['prompt' => 'A beat', 'duration' => 3, 'audio' => $wav, ...$input])
        ->assertSessionHasErrors($field);

    expect(AudioGeneration::count())->toBe(0);
})->with([
    'no file' => [['audio' => null], 'audio'],
    'not audio' => [['audio' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')], 'audio'],
    'not really a wav' => [['audio' => UploadedFile::fake()->createWithContent('music.wav', 'RIFF not a wave file')], 'audio'],
    'too long' => [['duration' => 60], 'duration'],
    'unknown device' => [['device' => 'cuda'], 'device'],
]);
