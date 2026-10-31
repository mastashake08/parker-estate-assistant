<?php

namespace App\Jobs;

use App\Models\AudioGeneration;
use App\Services\AudioService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateAudio implements ShouldQueue
{
    use Queueable;

    /**
     * Generations are not retried: the user can simply make another.
     */
    public int $tries = 1;

    /**
     * MusicGen on a CPU can take many minutes, and may download a model first.
     * The queue connection's retry_after must be longer than this.
     */
    public int $timeout = 1260;

    /**
     * Create a new job instance.
     */
    public function __construct(public AudioGeneration $generation) {}

    /**
     * Execute the job.
     */
    public function handle(AudioService $audio): void
    {
        $audio->generate($this->generation);
    }

    /**
     * Mark the generation failed if the job itself fails, such as by timing out.
     */
    public function failed(?Throwable $exception): void
    {
        app(AudioService::class)->fail($this->generation->refresh(), 'The audio took too long or could not be made. Please try again.');
    }
}
