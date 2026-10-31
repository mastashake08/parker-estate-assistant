<?php

namespace Database\Factories;

use App\Enums\RoutineRunStatus;
use App\Models\Routine;
use App\Models\RoutineRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoutineRun>
 */
class RoutineRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'routine_id' => Routine::factory(),
            'conversation_id' => null,
            'status' => RoutineRunStatus::Completed,
            'error' => null,
            'finished_at' => now(),
        ];
    }
}
