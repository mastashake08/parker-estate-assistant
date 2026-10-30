<?php

namespace App\Services\Mcp;

use App\Models\McpServer;
use Illuminate\Support\Collection;
use Laravel\Mcp\Client\Primitives\Tool;
use Laravel\Mcp\Exceptions\ClientException;

interface McpConnector
{
    /**
     * Connect to the server and list the definitions of the tools it exposes.
     *
     * @return list<array<string, mixed>> Each definition is the tool's MCP payload (name, description, inputSchema, ...).
     *
     * @throws ClientException
     */
    public function listTools(McpServer $server): array;

    /**
     * Turn tool definitions into tools that connect to the server when first called.
     *
     * @param  list<array<string, mixed>>  $definitions
     * @return Collection<int, Tool>
     */
    public function bind(McpServer $server, array $definitions): Collection;
}
