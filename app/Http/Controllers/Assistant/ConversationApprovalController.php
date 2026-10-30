<?php

namespace App\Http\Controllers\Assistant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assistant\StoreToolApprovalRequest;
use App\Services\AssistantService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ConversationApprovalController extends Controller
{
    public function __construct(protected AssistantService $assistant) {}

    /**
     * Approve or deny the tool calls a reply is waiting on, and resume the reply.
     */
    public function store(StoreToolApprovalRequest $request, string $conversation): RedirectResponse
    {
        $this->assistant->decide($request->user(), $conversation, $request->decisions());

        Inertia::flash('resumingReply', true);

        return to_route('chat.show', $conversation);
    }
}
