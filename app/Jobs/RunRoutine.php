<?php

namespace App\Jobs;

use App\Models\Routine;
use App\Services\RoutineService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunRoutine implements ShouldQueue
{
    use Queueable;

    /**
     * Runs are not retried: a failed run may already have sent an email or text.
     */
    public int $tries = 1;

    /**
     * Local models can be slow, especially when a routine uses several tools.
     */
    public int $timeout = 900;

    /**
     * Create a new job instance.
     */
    public function __construct(public Routine $routine) {}

    /**
     * Execute the job.
     */
    public function handle(RoutineService $routines): void
    {
        $routines->run($this->routine);
    }
}
