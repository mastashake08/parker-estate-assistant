<?php

namespace App\Policies;

use App\Models\McpServer;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class McpServerPolicy
{
    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, McpServer $mcpServer): Response
    {
        return $this->owns($user, $mcpServer);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, McpServer $mcpServer): Response
    {
        return $this->owns($user, $mcpServer);
    }

    /**
     * Hide records owned by other users rather than confirming they exist.
     */
    protected function owns(User $user, McpServer $mcpServer): Response
    {
        return $user->id === $mcpServer->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
