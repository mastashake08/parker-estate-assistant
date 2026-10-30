<?php

namespace App\Http\Controllers\Assistant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assistant\StoreConversationMessageRequest;
use App\Services\AssistantService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ConversationMessageController extends Controller
{
    public function __construct(protected AssistantService $assistant) {}

    /**
     * Send a message to the assistant, starting a conversation when none is given.
     *
     * The reply is streamed over the user's private channel, so the message is
     * flashed for the chat to show until the finished exchange is stored.
     */
    public function store(StoreConversationMessageRequest $request): RedirectResponse
    {
        $conversationId = $this->assistant->send(
            $request->user(),
            $request->validated('message'),
            $request->validated('conversation_id'),
        );

        Inertia::flash('pendingMessage', $request->validated('message'));

        return to_route('chat.show', $conversationId);
    }
}
