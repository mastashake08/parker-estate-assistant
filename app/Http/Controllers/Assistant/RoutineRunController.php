<?php

namespace App\Http\Controllers\Assistant;

use App\Http\Controllers\Controller;
use App\Models\Routine;
use App\Services\RoutineService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class RoutineRunController extends Controller
{
    public function __construct(protected RoutineService $routines) {}

    /**
     * Run a routine now, without changing when it next runs on schedule.
     */
    public function store(Routine $routine): RedirectResponse
    {
        Gate::authorize('update', $routine);

        $this->routines->runNow($routine);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name is running. Its result will appear in the chat.', ['name' => $routine->name])]);

        return to_route('routines.index');
    }
}
