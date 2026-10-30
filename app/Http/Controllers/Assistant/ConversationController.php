<?php

namespace App\Http\Controllers\Assistant;

use App\Http\Controllers\Controller;
use App\Services\ConversationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConversationController extends Controller
{
    public function __construct(protected ConversationService $conversations) {}

    /**
     * Show the chat with the user's conversation history.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('assistant/Chat', [
            'conversations' => $this->conversations->list($request->user()),
            'conversation' => null,
        ]);
    }

    /**
     * Show one of the user's conversations.
     */
    public function show(Request $request, string $conversation): Response
    {
        $found = $this->conversations->find($request->user(), $conversation);

        return Inertia::render('assistant/Chat', [
            'conversations' => $this->conversations->list($request->user()),
            'conversation' => [
                ...$found->only(['id', 'title']),
                'messages' => $this->conversations->messages($found)
                    ->map(fn ($message) => $message->only(['id', 'role', 'content', 'created_at'])),
            ],
        ]);
    }

    /**
     * Delete a conversation and its memory.
     */
    public function destroy(Request $request, string $conversation): RedirectResponse
    {
        $this->conversations->delete($this->conversations->find($request->user(), $conversation));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Conversation deleted.')]);

        return to_route('chat.index');
    }
}
