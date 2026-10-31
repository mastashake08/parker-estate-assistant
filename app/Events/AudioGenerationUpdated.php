<?php

namespace App\Events;

use App\Models\AudioGeneration;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A generation in the audio studio started, finished or failed. Sent to the
 * owner's private channel so the studio can refresh its library.
 */
class AudioGenerationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * Create a new event instance.
     */
    public function __construct(public AudioGeneration $generation) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("App.Models.User.{$this->generation->user_id}")];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'audio.generation';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array{id: int, status: string, error: string|null}
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->generation->id,
            'status' => $this->generation->status->value,
            'error' => $this->generation->error,
        ];
    }
}
