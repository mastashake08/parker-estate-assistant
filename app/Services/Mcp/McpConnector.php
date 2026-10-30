<?php

namespace App\Services\Mcp;

use App\Models\McpServer;
use Illuminate\Support\Collection;
use Laravel\Mcp\Client\Primitives\Tool;
use Laravel\Mcp\Exceptions\ClientException;

interface McpConnector
{
    /**
     * Connect to the server and list the tools it exposes.
     *
     * @return Collection<int, Tool>
     *
     * @throws ClientException
     */
    public function tools(McpServer $server): Collection;
}
