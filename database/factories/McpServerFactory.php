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

    /**
     * A remote server reached over HTTP.
     */
    public function http(): static
    {
        return $this->state(fn () => [
            'transport' => McpTransport::Http,
            'command' => null,
            'args' => null,
            'env' => null,
            'url' => 'https://mcp.acme.com/mcp',
        ]);
    }

    /**
     * A remote server the user has signed in to with OAuth.
     */
    public function signedIn(): static
    {
        return $this->http()->state(fn () => [
            'oauth_tokens' => [
                'access_token' => 'access-1',
                'refresh_token' => 'refresh-1',
                'expires_at' => now()->addHour()->getTimestamp(),
                'client_id' => 'registered-client',
                'client_secret' => null,
            ],
        ]);
    }
}
