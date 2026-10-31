<?php

namespace App\Http\Controllers\Audio;

use App\Http\Controllers\Controller;
use App\Http\Requests\Audio\StoreBrowserGenerationRequest;
use App\Services\Audio\AudioServiceException;
use App\Services\AudioService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class BrowserGenerationController extends Controller
{
    public function __construct(protected AudioService $audio) {}

    /**
     * Save music made in the browser to the library.
     */
    public function store(StoreBrowserGenerationRequest $request): RedirectResponse
    {
        $file = $request->file('audio');

        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['audio' => __('Upload one WAV file.')]);
        }

        try {
            $this->audio->storeBrowserMusic($request->user(), $request->music(), $file);
        } catch (AudioServiceException $exception) {
            throw ValidationException::withMessages(['audio' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved to your library.')]);

        return to_route('audio.index');
    }
}
