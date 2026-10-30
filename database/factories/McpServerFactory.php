<?php

namespace Database\Factories;

use App\Enums\McpTransport;
use App\Models\McpServer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<McpServer>
 */
class McpServerFactory extends Factory
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
            'name' => fake()->unique()->slug(2),
            'transport' => McpTransport::Stdio,
            'command' => 'npx',
            'args' => ['-y', '@modelcontextprotocol/server-everything'],
            'env' => [],
            'url' => null,
            'headers' => [],
            'enabled' => true,
        ];
    }
}
