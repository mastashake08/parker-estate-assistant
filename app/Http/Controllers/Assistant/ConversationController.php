<?php

namespace App\Http\Controllers\Assistant;

use App\Http\Controllers\Controller;
use App\Services\ConversationService;
use App\Services\OllamaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConversationController extends Controller
{
    public function __construct(
        protected ConversationService $conversations,
        protected OllamaService $ollama,
    ) {}

    /**
     * Show the chat with the user's conversation history.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('assistant/Chat', [
            ...$this->chatProps($request),
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
            ...$this->chatProps($request),
            'conversation' => [
                ...$found->only(['id', 'title', 'model']),
                'messages' => $this->conversations->messages($found),
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

    /**
     * The props every chat page needs: the conversation list and the models to choose from.
     *
     * @return array<string, mixed>
     */
    protected function chatProps(Request $request): array
    {
        return [
            'conversations' => $this->conversations->list($request->user()),
            'models' => $this->ollama->installedModels(),
            'defaultModel' => config('assistant.model'),
        ];
    }
}
