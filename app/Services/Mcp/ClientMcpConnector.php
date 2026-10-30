<?php

namespace App\Services\Mcp;

use App\Enums\McpTransport;
use App\Models\McpServer;
use Illuminate\Support\Collection;
use Laravel\Mcp\Client;
use Laravel\Mcp\Client\Primitives\Tool;

class ClientMcpConnector implements McpConnector
{
    /**
     * Connect to the server and list the definitions of the tools it exposes.
     *
     * @return list<array<string, mixed>>
     */
    public function listTools(McpServer $server): array
    {
        $client = $this->client($server);

        try {
            return array_values($client->tools()->map(fn (Tool $tool) => [
                'name' => $tool->name,
                'title' => $tool->title,
                'description' => $tool->description,
                'inputSchema' => $tool->inputSchema,
                'outputSchema' => $tool->outputSchema,
                'annotations' => $tool->annotations,
                '_meta' => $tool->meta,
            ])->all());
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Turn tool definitions into tools that connect to the server when first called.
     *
     * The client only connects (starting a local server's process) when one of
     * the tools is called.
     *
     * @param  list<array<string, mixed>>  $definitions
     * @return Collection<int, Tool>
     */
    public function bind(McpServer $server, array $definitions): Collection
    {
        $client = $this->client($server);

        return collect($definitions)->map(fn (array $definition) => Tool::from($client, $definition));
    }

    /**
     * Build an MCP client for the given server.
     */
    protected function client(McpServer $server): Client
    {
        $client = match ($server->transport) {
            McpTransport::Stdio => Client::local((string) $server->command, $server->args ?? [], $server->env ?? []),
            McpTransport::Http => Client::web((string) $server->url)->withHeaders($server->headers ?? []),
        };

        return $client
            ->setName($server->name)
            ->withTimeout((float) ($server->timeout ?? config('assistant.mcp.timeout')));
    }
}
