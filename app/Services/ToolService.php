<?php

namespace App\Services;

use App\Models\McpServer;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Mcp\Client\Exceptions\AuthorizationRequiredException;
use Laravel\Mcp\Client\Primitives\Tool as McpTool;
use Throwable;

/**
 * Tools are identified by a key: "builtin:{snake_name}" for tools that ship with
 * the app, and "mcp:{server id}:{tool name}" for tools exposed by an MCP server.
 */
class ToolService
{
    public function __construct(protected McpServerService $servers) {}

    /**
     * Describe every tool available to the user and whether it is enabled.
     *
     * @return array{
     *     builtin: array<int, array{key: string, name: string, description: string, enabled: bool}>,
     *     servers: array<int, array{id: int, name: string, error: string|null, signInRequired: bool, tools: array<int, array{key: string, name: string, description: string, enabled: bool}>}>
     * }
     */
    public function catalog(User $user): array
    {
        $disabled = $this->disabledKeys($user);

        $builtin = $this->builtinTools()
            ->map(fn (Tool $tool, string $key) => [
                'key' => $key,
                'name' => Str::after($key, 'builtin:'),
                'description' => (string) $tool->description(),
                'enabled' => ! $disabled->contains($key),
            ])
            ->values()
            ->all();

        $servers = $this->servers->enabled($user)
            ->map(function (McpServer $server) use ($disabled) {
                try {
                    $tools = $this->servers->tools($server);
                    $error = null;
                    $signInRequired = false;
                } catch (AuthorizationRequiredException) {
                    $tools = collect();
                    $error = 'Sign in to this server to use its tools.';
                    $signInRequired = true;
                } catch (Throwable $exception) {
                    $tools = collect();
                    $error = $exception->getMessage();
                    $signInRequired = false;
                }

                return [
                    'id' => $server->id,
                    'name' => $server->name,
                    'error' => $error,
                    'signInRequired' => $signInRequired,
                    'tools' => $tools->map(fn (McpTool $tool) => [
                        'key' => $server->toolKey($tool->name),
                        'name' => $tool->name,
                        'description' => (string) ($tool->description ?? $tool->title ?? ''),
                        'enabled' => ! $disabled->contains($server->toolKey($tool->name)),
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();

        return ['builtin' => $builtin, 'servers' => $servers];
    }

    /**
     * Get the tools the assistant may use for the user.
     *
     * An MCP server that cannot be reached is reported and skipped so the
     * assistant can still reply without it.
     *
     * @return list<Tool|McpTool>
     */
    public function enabledTools(User $user): array
    {
        $disabled = $this->disabledKeys($user);

        $builtin = $this->builtinTools()
            ->reject(fn (Tool $tool, string $key) => $disabled->contains($key))
            ->values();

        $mcp = $this->servers->enabled($user)->flatMap(function (McpServer $server) use ($disabled) {
            try {
                return $this->servers->tools($server)
                    ->reject(fn (McpTool $tool) => $disabled->contains($server->toolKey($tool->name)));
            } catch (Throwable $exception) {
                report($exception);

                return [];
            }
        });

        return [...$builtin, ...$mcp];
    }

    /**
     * Switch a tool on or off for the user.
     */
    public function setEnabled(User $user, string $key, bool $enabled): void
    {
        if ($enabled) {
            $user->disabledTools()->where('tool', $key)->delete();

            return;
        }

        $user->disabledTools()->firstOrCreate(['tool' => $key]);
    }

    /**
     * Determine whether the key names a tool the user is allowed to configure.
     */
    public function isKnown(User $user, string $key): bool
    {
        if ($this->builtinTools()->has($key)) {
            return true;
        }

        if (! preg_match('/^mcp:(\d+):.+$/', $key, $matches)) {
            return false;
        }

        return $user->mcpServers()->whereKey((int) $matches[1])->exists();
    }

    /**
     * Instantiate the application's built-in tools, keyed by tool key.
     *
     * @return Collection<string, Tool>
     */
    protected function builtinTools(): Collection
    {
        return collect(config()->array('assistant.tools', []))
            ->mapWithKeys(fn (string $class) => [
                'builtin:'.Str::snake(class_basename($class)) => app($class),
            ]);
    }

    /**
     * Get the keys of the tools the user has switched off.
     *
     * @return Collection<int, string>
     */
    protected function disabledKeys(User $user): Collection
    {
        return $user->disabledTools()->pluck('tool');
    }
}
