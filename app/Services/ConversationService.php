<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;

class ConversationService
{
    /**
     * The longest tool result shown in the transcript, in characters.
     */
    protected const MAX_TOOL_RESULT_CHARACTERS = 2_000;

    public function __construct(protected ConversationStore $store) {}

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
     * Start a new conversation for the user, titled after their first message.
     */
    public function start(User $user, string $firstMessage, ?string $model = null): string
    {
        $conversationId = $this->store->storeConversation(
            Conversation::participantType($user),
            Conversation::participantKey($user),
            Str::limit(Str::squish($firstMessage), 50, preserveWords: true),
        );

        if ($model !== null) {
            $this->setModel($conversationId, $model);
        }

        return $conversationId;
    }

    /**
     * Choose the model that replies in a conversation.
     */
    public function setModel(string $conversationId, string $model): void
    {
        Conversation::query()->whereKey($conversationId)->update(['model' => $model]);
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
     * Get a conversation's messages in the order they were sent, with the tools
     * the assistant used for each reply and the reply rendered from Markdown.
     *
     * @return array<int, array{id: string, role: string, content: ?string, html: ?string, created_at: mixed, tools: array<int, array{name: mixed, arguments: mixed, result: string, failed: bool}>}>
     */
    public function messages(Conversation $conversation): array
    {
        return $conversation->messages()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'conversation_id', 'role', 'content', 'steps', 'created_at'])
            ->map(fn (ConversationMessage $message) => [
                ...$message->only(['id', 'role', 'content', 'created_at']),
                'html' => $message->role === 'assistant' && filled($message->content) ? $this->markdown($message->content) : null,
                'tools' => array_map(fn (array $tool) => [
                    'name' => $tool['name'],
                    'arguments' => $tool['arguments'],
                    'result' => Str::limit($this->resultText($tool['result'] ?? ''), self::MAX_TOOL_RESULT_CHARACTERS),
                    'failed' => ($tool['failed'] ?? false) || ($tool['denied'] ?? false),
                ], $message->tool_results),
            ])
            ->values()
            ->all();
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
     * Render the assistant's Markdown as HTML that is safe to show.
     *
     * HTML the model writes is escaped rather than rendered, and links that
     * could run script (javascript:, data:, ...) are removed.
     */
    protected function markdown(string $content): string
    {
        return Str::markdown($content, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * Convert a stored tool result to text.
     */
    protected function resultText(mixed $result): string
    {
        return is_string($result) ? $result : (string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
