<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;

class ConversationService
{
    /**
     * Get the user's conversations, most recently active first.
     *
     * @return Collection<int, Conversation>
     */
    public function list(User $user): Collection
    {
        return $this->ownedBy($user)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get(['id', 'title', 'updated_at']);
    }

    /**
     * Find one of the user's conversations.
     *
     * @throws ModelNotFoundException<Conversation>
     */
    public function find(User $user, string $conversationId): Conversation
    {
        return $this->ownedBy($user)->findOrFail($conversationId);
    }

    /**
     * Get a conversation's messages in the order they were sent.
     *
     * @return Collection<int, ConversationMessage>
     */
    public function messages(Conversation $conversation): Collection
    {
        return $conversation->messages()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'conversation_id', 'role', 'content', 'created_at']);
    }

    /**
     * Delete a conversation and everything the assistant remembered from it.
     */
    public function delete(Conversation $conversation): void
    {
        DB::transaction(function () use ($conversation) {
            $conversation->messages()->delete();
            $conversation->delete();
        });
    }

    /**
     * Start a query for the conversations the user took part in.
     *
     * @return Builder<Conversation>
     */
    protected function ownedBy(User $user): Builder
    {
        return Conversation::query()
            ->where('participant_type', Conversation::participantType($user))
            ->where('participant_id', Conversation::participantKey($user));
    }
}
