<?php

namespace App\Services;

use App\Ai\Agents\EstateAssistant;
use App\Events\AssistantReplyStreamed;
use App\Jobs\StreamAssistantReply;
use App\Models\User;
use Illuminate\Support\Arr;
use Laravel\Ai\Streaming\Events\StreamEvent;

class AssistantService
{
    public function __construct(
        protected ToolService $tools,
        protected SkillService $skills,
        protected ConversationService $conversations,
    ) {}

    /**
     * Queue the assistant's reply to the user's message and return the conversation ID.
     *
     * Passing a conversation ID continues that conversation; otherwise a new one
     * is started. The reply is streamed to the user over their private channel.
     */
    public function send(User $user, string $message, ?string $conversationId = null): string
    {
        $conversationId = $conversationId === null
            ? $this->conversations->start($user, $message)
            : $this->conversations->find($user, $conversationId)->id;

        StreamAssistantReply::dispatch($user, $conversationId, $message);

        return $conversationId;
    }

    /**
     * Prompt the assistant and broadcast each streamed event as it arrives.
     *
     * The conversation is remembered once the stream completes, so the next
     * message in it gives the model this exchange as context. A final
     * "reply_complete" event tells the chat the exchange has been stored.
     */
    public function stream(User $user, string $conversationId, string $message): void
    {
        $agent = new EstateAssistant(
            tools: $this->tools->enabledTools($user),
            skills: $this->skills->enabledFor($user),
        );

        $agent
            ->continue($conversationId, as: $user)
            ->stream(
                $message,
                provider: config('assistant.provider'),
                model: config('assistant.model'),
            )
            ->each(fn (StreamEvent $event) => AssistantReplyStreamed::dispatch($user, $conversationId, $this->broadcastable($event)));

        AssistantReplyStreamed::dispatch($user, $conversationId, ['type' => 'reply_complete']);
    }

    /**
     * Convert a stream event to the payload sent to the browser.
     *
     * Tool output (a whole web page, for example) can be far larger than a
     * broadcast may be, so it is left out. The stored conversation keeps it.
     *
     * @return array<string, mixed>
     */
    protected function broadcastable(StreamEvent $event): array
    {
        return Arr::except($event->toArray(), ['result']);
    }
}
