<?php

namespace App\Services;

use App\Ai\Agents\EstateAssistant;
use App\Ai\Tools\ReadDocument;
use App\Events\AssistantReplyStreamed;
use App\Jobs\StreamAssistantReply;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Streaming\Events\StreamEvent;

class AssistantService
{
    public function __construct(
        protected ToolService $tools,
        protected SkillService $skills,
        protected ConversationService $conversations,
        protected DocumentService $documents,
    ) {}

    /**
     * Queue the assistant's reply to the user's message and return the conversation ID.
     *
     * Passing a conversation ID continues that conversation; otherwise a new one
     * is started. Passing a model switches the conversation to it, and attached
     * documents are added to it. The reply is streamed to the user over their
     * private channel.
     *
     * @param  array<int, UploadedFile>  $documents
     *
     * @throws ValidationException When a document cannot be read.
     */
    public function send(User $user, string $message, ?string $conversationId = null, ?string $model = null, array $documents = []): string
    {
        // Read the documents first, so nothing is stored if one cannot be read.
        $readDocuments = $this->documents->read($documents);

        if ($conversationId === null) {
            $conversationId = $this->conversations->start($user, $message, $model);
        } else {
            $conversationId = $this->conversations->find($user, $conversationId)->id;

            if ($model !== null) {
                $this->conversations->setModel($conversationId, $model);
            }
        }

        $this->documents->store($user, $conversationId, $readDocuments);

        StreamAssistantReply::dispatch($user, $conversationId, $message);

        return $conversationId;
    }

    /**
     * Approve or deny the tool calls the conversation's reply is waiting on, and
     * queue the rest of the reply.
     *
     * Every waiting call must be decided at once.
     *
     * @param  array<string, bool>  $decisions  Whether each waiting call is approved, keyed by its ID.
     *
     * @throws ValidationException
     */
    public function decide(User $user, string $conversationId, array $decisions): void
    {
        $conversation = $this->conversations->find($user, $conversationId);

        $waiting = array_column($this->conversations->approvals($conversation), 'id');

        if (collect($waiting)->sort()->values()->all() !== collect(array_keys($decisions))->map(fn ($id) => (string) $id)->sort()->values()->all()) {
            throw ValidationException::withMessages([
                'decisions' => __('Approve or deny each request that is waiting for approval.'),
            ]);
        }

        StreamAssistantReply::dispatch($user, $conversation->id, $decisions);
    }

    /**
     * Prompt the assistant and broadcast each streamed event as it arrives.
     *
     * The conversation is remembered once the stream completes, so the next
     * message in it gives the model this exchange as context. A final
     * "reply_complete" event tells the chat the exchange has been stored.
     *
     * The input is the user's message, or their decisions on the tool calls a
     * paused reply is waiting on, which resume it.
     *
     * @param  string|array<string, bool>  $input
     */
    public function stream(User $user, string $conversationId, string|array $input): void
    {
        $documents = $this->documents->forConversation($user, $conversationId);

        $agent = new EstateAssistant(
            tools: [
                ...$this->tools->enabledTools($user),
                ...($documents->isNotEmpty() ? [new ReadDocument($documents)] : []),
            ],
            skills: $this->skills->enabledFor($user),
            documents: $documents,
        );

        $agent
            ->continue($conversationId, as: $user)
            ->stream(
                is_array($input) ? Decisions::from($input) : $input,
                provider: config('assistant.provider'),
                model: $this->conversations->find($user, $conversationId)->model ?? config('assistant.model'),
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
