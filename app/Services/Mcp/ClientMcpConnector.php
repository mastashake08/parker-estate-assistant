<?php

namespace App\Services\Mcp;

use App\Enums\McpTransport;
use App\Models\McpServer;
use Illuminate\Support\Collection;
use Laravel\Mcp\Client;

class ClientMcpConnector implements McpConnector
{
    /**
     * Connect to the server and list the tools it exposes.
     *
     * @return Collection<int, Client\Primitives\Tool>
     */
    public function tools(McpServer $server): Collection
    {
        return $this->client($server)->tools()->values();
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
