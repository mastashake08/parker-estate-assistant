<?php

namespace App\Http\Controllers\Audio;

use App\Http\Controllers\Controller;
use App\Models\AudioGeneration;
use App\Services\Audio\DiffSingerClient;
use App\Services\Audio\MusicGenClient;
use App\Services\Audio\PhononClient;
use App\Services\AudioService;
use App\Services\OllamaService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AudioController extends Controller
{
    public function __construct(
        protected AudioService $audio,
        protected PhononClient $phonon,
        protected MusicGenClient $musicGen,
        protected DiffSingerClient $diffSinger,
        protected OllamaService $ollama,
    ) {}

    /**
     * Show the audio studio: the user's library, and the engines that make speech, music, MIDI and vocals.
     *
     * The engines are asked for their state after the page has loaded, so a
     * server that is down does not hold the page up.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('audio/Studio', [
            'generations' => fn () => $this->audio->list($request->user())->map(fn (AudioGeneration $generation) => $generation->only([
                'id', 'kind', 'status', 'title', 'prompt', 'model', 'options', 'size', 'duration', 'stems', 'error', 'created_at', 'finished_at',
            ]) + ['has_score' => $generation->score_path !== null]),
            'speech' => Inertia::defer(fn () => $this->phonon->servers(), 'engines'),
            'music' => Inertia::defer(fn () => $this->musicGen->status(), 'engines'),
            'singing' => Inertia::defer(fn () => $this->diffSinger->status(), 'engines'),
            'midiModels' => Inertia::defer(fn () => $this->ollama->installedModels(), 'engines'),
            'limits' => [
                'speechCharacters' => config()->integer('audio.speech.max_characters'),
                'musicSeconds' => config()->integer('audio.music.max_seconds'),
                'midiBars' => config()->integer('audio.midi.max_bars'),
                'browserSeconds' => config()->integer('audio.music.browser_max_seconds'),
            ],
            'defaults' => [
                'musicModel' => config('audio.music.model'),
                'browserMusicModel' => config('audio.music.browser_model'),
                'midiModel' => config('audio.midi.model'),
                'singingVoice' => config('audio.singing.voice'),
            ],
        ]);
    }
}
