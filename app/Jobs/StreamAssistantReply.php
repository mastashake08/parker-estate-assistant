<?php

namespace App\Jobs;

use App\Events\AssistantReplyStreamed;
use App\Models\User;
use App\Services\AssistantService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class StreamAssistantReply implements ShouldQueue
{
    use Queueable;

    /**
     * Replies are not retried: part of a failed reply may already have been streamed.
     */
    public int $tries = 1;

    /**
     * Local models can be slow, especially when they call tools.
     */
    public int $timeout = 600;

    /**
     * Create a new job instance.
     *
     * @param  string|array<string, bool>  $input  The user's message, or their approval decisions keyed by tool call ID.
     */
    public function __construct(
        public User $user,
        public string $conversationId,
        public string|array $input,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(AssistantService $assistant): void
    {
        $assistant->stream($this->user, $this->conversationId, $this->input);
    }

    /**
     * Tell the chat the reply failed so it stops waiting.
     */
    public function failed(?Throwable $exception): void
    {
        AssistantReplyStreamed::dispatch($this->user, $this->conversationId, [
            'type' => 'error',
            'message' => 'The assistant could not reply. Please try again.',
        ]);
    }
}
