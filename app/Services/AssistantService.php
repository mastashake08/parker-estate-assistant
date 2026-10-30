<?php

namespace App\Services;

use App\Ai\Agents\EstateAssistant;
use App\Models\User;

class AssistantService
{
    public function __construct(
        protected ToolService $tools,
        protected SkillService $skills,
        protected ConversationService $conversations,
    ) {}

    /**
     * Send the user's message to the assistant and return the conversation ID.
     *
     * Passing a conversation ID continues that conversation, so the model sees
     * the earlier messages; otherwise a new conversation is started.
     */
    public function reply(User $user, string $message, ?string $conversationId = null): string
    {
        if ($conversationId !== null) {
            $this->conversations->find($user, $conversationId);
        }

        $agent = new EstateAssistant(
            tools: $this->tools->enabledTools($user),
            skills: $this->skills->enabledFor($user),
        );

        $response = $agent
            ->continueOrStart($conversationId, as: $user)
            ->prompt(
                $message,
                provider: config('assistant.provider'),
                model: config('assistant.model'),
            );

        return $response->conversationId ?? $conversationId;
    }
}
