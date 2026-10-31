<?php

namespace Database\Factories;

use App\Enums\RoutineFrequency;
use App\Models\Routine;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Routine>
 */
class RoutineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->words(2, true),
            'instructions' => 'Give me a short morning brief with today\'s top news.',
            'frequency' => RoutineFrequency::Daily,
            'time' => '08:00',
            'cron' => '0 8 * * *',
            'timezone' => 'UTC',
            'model' => null,
            'tools' => ['builtin:web_search'],
            'auto_approve' => false,
            'deliver_email' => false,
            'deliver_sms' => false,
            'sms_to' => null,
            'enabled' => true,
            'next_run_at' => now()->addDay(),
        ];
    }

    /**
     * A routine whose next run is due.
     */
    public function due(): static
    {
        return $this->state(fn () => ['next_run_at' => now()->subMinute()]);
    }
}
