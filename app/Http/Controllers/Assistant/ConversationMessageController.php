<?php

namespace App\Http\Controllers\Assistant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assistant\StoreConversationMessageRequest;
use App\Services\AssistantService;
use Illuminate\Http\RedirectResponse;

class ConversationMessageController extends Controller
{
    public function __construct(protected AssistantService $assistant) {}

    /**
     * Send a message to the assistant, starting a conversation when none is given.
     */
    public function store(StoreConversationMessageRequest $request): RedirectResponse
    {
        $conversationId = $this->assistant->reply(
            $request->user(),
            $request->validated('message'),
            $request->validated('conversation_id'),
        );

        return to_route('chat.show', $conversationId);
    }
}
