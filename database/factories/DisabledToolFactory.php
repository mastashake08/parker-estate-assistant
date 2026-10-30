<?php

namespace Database\Factories;

use App\Models\DisabledTool;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DisabledTool>
 */
class DisabledToolFactory extends Factory
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
            'tool' => 'builtin:current_date_time',
        ];
    }
}
