<?php

namespace App\Policies;

use App\Models\AudioGeneration;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class AudioGenerationPolicy
{
    /**
     * Determine whether the user can play or download the generation.
     */
    public function view(User $user, AudioGeneration $generation): Response
    {
        return $this->owns($user, $generation);
    }

    /**
     * Determine whether the user can delete the generation.
     */
    public function delete(User $user, AudioGeneration $generation): Response
    {
        return $this->owns($user, $generation);
    }

    /**
     * Hide records owned by other users rather than confirming they exist.
     */
    protected function owns(User $user, AudioGeneration $generation): Response
    {
        return $user->id === $generation->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
