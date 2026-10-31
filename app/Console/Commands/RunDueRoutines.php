<?php

namespace App\Console\Commands;

use App\Services\RoutineService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('routines:run-due')]
#[Description('Queue every routine whose next run is due')]
class RunDueRoutines extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RoutineService $routines): int
    {
        $queued = $routines->dispatchDue();

        $this->components->info("Queued {$queued} routine(s).");

        return self::SUCCESS;
    }
}
