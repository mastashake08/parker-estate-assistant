<?php

namespace App\Http\Controllers\Assistant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assistant\StoreRoutineRequest;
use App\Http\Requests\Assistant\UpdateRoutineRequest;
use App\Models\Routine;
use App\Models\RoutineRun;
use App\Services\OllamaService;
use App\Services\RoutineService;
use App\Services\ToolService;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RoutineController extends Controller
{
    public function __construct(
        protected RoutineService $routines,
        protected ToolService $tools,
        protected OllamaService $ollama,
    ) {}

    /**
     * Show the user's routines, with the tools and models a routine can use.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('assistant/Routines', [
            'routines' => $this->routines->list($request->user())->map(fn (Routine $routine) => [
                ...$routine->only([
                    'id', 'name', 'instructions', 'frequency', 'time', 'day_of_week', 'every_hours', 'cron', 'timezone',
                    'model', 'tools', 'auto_approve', 'deliver_email', 'deliver_sms', 'sms_to', 'enabled', 'next_run_at', 'last_run_at',
                ]),
                'schedule' => $this->routines->describe($routine),
                'runs' => $routine->runs->map(fn (RoutineRun $run) => $run->only(['id', 'status', 'error', 'conversation_id', 'created_at', 'finished_at'])),
            ]),
            'tools' => $this->tools->catalog($request->user()),
            'models' => $this->ollama->installedModels(),
            'defaultModel' => config('assistant.model'),
            // The browser names some zones by aliases PHP does not know, so the page offers PHP's own.
            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
    }

    /**
     * Schedule a routine.
     */
    public function store(StoreRoutineRequest $request): RedirectResponse
    {
        $this->routines->create($request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Routine scheduled.')]);

        return to_route('routines.index');
    }

    /**
     * Update a routine.
     */
    public function update(UpdateRoutineRequest $request, Routine $routine): RedirectResponse
    {
        $this->routines->update($routine, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Routine updated.')]);

        return to_route('routines.index');
    }

    /**
     * Delete a routine.
     */
    public function destroy(Routine $routine): RedirectResponse
    {
        Gate::authorize('delete', $routine);

        $this->routines->delete($routine);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Routine deleted.')]);

        return to_route('routines.index');
    }
}
