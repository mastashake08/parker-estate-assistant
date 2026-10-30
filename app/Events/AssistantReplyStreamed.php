<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One piece of the assistant's streamed reply (a text delta, tool call, the end
 * of the stream, or an error), sent to the user's private channel.
 */
class AssistantReplyStreamed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * Create a new event instance.
     *
     * @param  array<string, mixed>  $event  The Laravel AI stream event, as an array with a "type" key.
     */
    public function __construct(
        public User $user,
        public string $conversationId,
        public array $event,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("App.Models.User.{$this->user->id}")];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'assistant.stream';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array{conversation_id: string, event: array<string, mixed>}
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'event' => $this->event,
        ];
    }
}
