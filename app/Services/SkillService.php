<?php

namespace App\Services;

use App\Models\Skill;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Ai\Skills\Skill as AiSkill;

class SkillService
{
    /**
     * Get every skill the user has written.
     *
     * @return Collection<int, Skill>
     */
    public function list(User $user): Collection
    {
        return $user->skills()->orderBy('name')->get();
    }

    /**
     * Create a skill for the user.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $user, array $attributes): Skill
    {
        return $user->skills()->create($attributes);
    }

    /**
     * Update a skill.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Skill $skill, array $attributes): Skill
    {
        $skill->update($attributes);

        return $skill;
    }

    /**
     * Delete a skill.
     */
    public function delete(Skill $skill): void
    {
        $skill->delete();
    }

    /**
     * Get the user's enabled skills in the form the agent loads them.
     *
     * @return array<int, AiSkill>
     */
    public function enabledFor(User $user): array
    {
        return $user->skills()
            ->where('enabled', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Skill $skill) => $skill->toAiSkill())
            ->all();
    }
}
