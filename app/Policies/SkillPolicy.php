<?php

namespace App\Policies;

use App\Models\Skill;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class SkillPolicy
{
    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Skill $skill): Response
    {
        return $this->owns($user, $skill);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Skill $skill): Response
    {
        return $this->owns($user, $skill);
    }

    /**
     * Hide records owned by other users rather than confirming they exist.
     */
    protected function owns(User $user, Skill $skill): Response
    {
        return $user->id === $skill->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
