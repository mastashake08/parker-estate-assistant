<?php

namespace App\Http\Controllers\Audio;

use App\Http\Controllers\Controller;
use App\Http\Requests\Audio\StoreAudioGenerationRequest;
use App\Models\AudioGeneration;
use App\Services\AudioService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class AudioGenerationController extends Controller
{
    public function __construct(protected AudioService $audio) {}

    /**
     * Queue speech, music or MIDI to be made.
     */
    public function store(StoreAudioGenerationRequest $request): RedirectResponse
    {
        $this->audio->queue($request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Generating. It will appear in your library when it is ready.')]);

        return to_route('audio.index');
    }

    /**
     * Play or download a generation's file, one stem of its MIDI, or (for
     * vocals) the DiffSinger score it was sung from (?format=ds).
     */
    public function show(Request $request, AudioGeneration $generation): Response
    {
        Gate::authorize('view', $generation);

        $stem = $request->filled('stem') ? $request->integer('stem') : null;

        $file = $this->audio->file($generation, $stem, score: $request->query('format') === 'ds');

        abort_if($file === null, 404);

        return response($file['contents'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$file['name'].'"',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * Delete a generation and its file.
     */
    public function destroy(AudioGeneration $generation): RedirectResponse
    {
        Gate::authorize('delete', $generation);

        $this->audio->delete($generation);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Deleted.')]);

        return to_route('audio.index');
    }
}
