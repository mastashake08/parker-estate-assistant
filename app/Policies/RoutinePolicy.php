<?php

namespace App\Policies;

use App\Models\Routine;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class RoutinePolicy
{
    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Routine $routine): Response
    {
        return $this->owns($user, $routine);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Routine $routine): Response
    {
        return $this->owns($user, $routine);
    }

    /**
     * Hide records owned by other users rather than confirming they exist.
     */
    protected function owns(User $user, Routine $routine): Response
    {
        return $user->id === $routine->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
