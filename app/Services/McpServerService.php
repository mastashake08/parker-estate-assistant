<?php

namespace App\Services;

use App\Enums\McpTransport;
use App\Models\McpServer;
use App\Models\User;
use App\Services\Mcp\McpConnector;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Client\Primitives\Tool;

class McpServerService
{
    public function __construct(protected McpConnector $connector) {}

    /**
     * Get every MCP server the user has connected.
     *
     * @return Collection<int, McpServer>
     */
    public function list(User $user): Collection
    {
        return $user->mcpServers()->orderBy('name')->get();
    }

    /**
     * Get the servers the assistant should connect to for the user.
     *
     * @return Collection<int, McpServer>
     */
    public function enabled(User $user): Collection
    {
        return $user->mcpServers()->enabled()->orderBy('name')->get();
    }

    /**
     * Connect a new MCP server for the user.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $user, array $attributes): McpServer
    {
        return $user->mcpServers()->create($this->normalize($attributes));
    }

    /**
     * Update an MCP server's connection details.
     *
     * Secrets (env, headers and the OAuth client secret) are only replaced
     * when they are submitted. Changing the URL or OAuth client signs out,
     * since the tokens belong to the old one.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(McpServer $server, array $attributes): McpServer
    {
        $server->fill($this->normalize($attributes));

        if ($server->isDirty(['transport', 'url', 'oauth_client_id', 'oauth_client_secret'])) {
            $server->oauth_tokens = null;
        }

        $server->save();

        return $server;
    }

    /**
     * Disconnect an MCP server and forget the user's preferences for its tools.
     */
    public function delete(McpServer $server): void
    {
        DB::transaction(function () use ($server) {
            $server->user->disabledTools()
                ->where('tool', 'like', $server->toolKey('%'))
                ->delete();

            $server->delete();
        });

        Cache::forget($this->toolCacheKey($server));
    }

    /**
     * List the tools the server exposes.
     *
     * The list is cached so the server is not started on every message. Editing
     * the server changes the cache key, and a failed connection is not cached.
     *
     * @return BaseCollection<int, Tool>
     */
    public function tools(McpServer $server): BaseCollection
    {
        $definitions = Cache::remember(
            $this->toolCacheKey($server),
            config()->integer('assistant.mcp.tool_cache_seconds'),
            fn () => $this->connector->listTools($server),
        );

        return $this->connector->bind($server, $definitions);
    }

    /**
     * Forget the server's cached tools so they are listed again.
     */
    public function refreshTools(McpServer $server): void
    {
        Cache::forget($this->toolCacheKey($server));
    }

    /**
     * The cache key for the server's tools, which changes whenever the server is edited.
     */
    protected function toolCacheKey(McpServer $server): string
    {
        return "mcp-tools:{$server->id}:{$server->updated_at?->getTimestamp()}";
    }

    /**
     * Clear the fields that do not apply to the chosen transport.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function normalize(array $attributes): array
    {
        return match (McpTransport::tryFrom($attributes['transport'] ?? '')) {
            McpTransport::Stdio => [...$attributes, 'url' => null, 'headers' => null, 'oauth_client_id' => null, 'oauth_client_secret' => null, 'oauth_scope' => null],
            McpTransport::Http => [...$attributes, 'command' => null, 'args' => null, 'env' => null],
            default => $attributes,
        };
    }
}
