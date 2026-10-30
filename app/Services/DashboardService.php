<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Ai\Models\Conversation;

class DashboardService
{
    /**
     * The number of conversations listed on the dashboard.
     */
    protected const RECENT_CONVERSATIONS = 5;

    public function __construct(
        protected ConversationService $conversations,
        protected OllamaService $ollama,
    ) {}

    /**
     * Count what the user has set up and talked about.
     *
     * @return array{conversations: int, documents: int, skills: array{enabled: int, total: int}, mcpServers: array{enabled: int, total: int}}
     */
    public function stats(User $user): array
    {
        return [
            'conversations' => $this->conversations->count($user),
            'documents' => $user->documents()->count(),
            'skills' => $this->enabledCount($user->skills()),
            'mcpServers' => $this->enabledCount($user->mcpServers()),
        ];
    }

    /**
     * Get the user's most recently active conversations.
     *
     * @return Collection<int, Conversation>
     */
    public function recentConversations(User $user): Collection
    {
        return $this->conversations->recent($user, self::RECENT_CONVERSATIONS);
    }

    /**
     * Describe the default model and whether Ollama has it installed.
     *
     * @return array{name: string, installed: bool, available: list<string>}
     */
    public function model(): array
    {
        $name = (string) config('assistant.model');
        $available = $this->ollama->installedModels();

        return [
            'name' => $name,
            'installed' => in_array($name, $available, true),
            'available' => $available,
        ];
    }

    /**
     * Count the enabled and total records of a relation with an `enabled` column.
     *
     * @param  HasMany<covariant \Illuminate\Database\Eloquent\Model, User>  $relation
     * @return array{enabled: int, total: int}
     */
    protected function enabledCount(HasMany $relation): array
    {
        return [
            'enabled' => (clone $relation)->where('enabled', true)->count(),
            'total' => $relation->count(),
        ];
    }
}
