<?php

namespace App\Http\Controllers\Assistant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assistant\StoreSkillRequest;
use App\Http\Requests\Assistant\UpdateSkillRequest;
use App\Models\Skill;
use App\Services\SkillService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SkillController extends Controller
{
    public function __construct(protected SkillService $skills) {}

    /**
     * Show the user's skills.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('assistant/Skills', [
            'skills' => $this->skills->list($request->user()),
        ]);
    }

    /**
     * Create a skill.
     */
    public function store(StoreSkillRequest $request): RedirectResponse
    {
        $this->skills->create($request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Skill added.')]);

        return to_route('skills.index');
    }

    /**
     * Update a skill.
     */
    public function update(UpdateSkillRequest $request, Skill $skill): RedirectResponse
    {
        $this->skills->update($skill, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Skill updated.')]);

        return to_route('skills.index');
    }

    /**
     * Delete a skill.
     */
    public function destroy(Skill $skill): RedirectResponse
    {
        Gate::authorize('delete', $skill);

        $this->skills->delete($skill);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Skill deleted.')]);

        return to_route('skills.index');
    }
}
