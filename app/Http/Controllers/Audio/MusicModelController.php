<?php

namespace App\Http\Controllers\Audio;

use App\Http\Controllers\Controller;
use App\Services\Audio\AudioServiceException;
use App\Services\Audio\MusicGenClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class MusicModelController extends Controller
{
    public function __construct(protected MusicGenClient $musicGen) {}

    /**
     * Load a MusicGen model into memory, replacing the one there.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'model' => ['required', 'string', Rule::in(config('audio.music.models'))],
        ]);

        try {
            $this->musicGen->load($validated['model']);
        } catch (AudioServiceException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return to_route('audio.index');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Loading :model. The first load downloads it, which can take a while.', ['model' => $validated['model']])]);

        return to_route('audio.index');
    }
}
