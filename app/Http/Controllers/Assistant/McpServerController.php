<?php

namespace App\Http\Controllers\Assistant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assistant\StoreMcpServerRequest;
use App\Http\Requests\Assistant\UpdateMcpServerRequest;
use App\Models\McpServer;
use App\Services\McpServerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class McpServerController extends Controller
{
    public function __construct(protected McpServerService $servers) {}

    /**
     * Show the user's MCP servers.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('assistant/McpServers', [
            'servers' => $this->servers->list($request->user()),
            'stdioEnabled' => (bool) config('assistant.mcp.stdio_enabled'),
        ]);
    }

    /**
     * Connect a new MCP server.
     */
    public function store(StoreMcpServerRequest $request): RedirectResponse
    {
        $this->servers->create($request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('MCP server added.')]);

        return to_route('mcp-servers.index');
    }

    /**
     * Update an MCP server.
     */
    public function update(UpdateMcpServerRequest $request, McpServer $mcpServer): RedirectResponse
    {
        $this->servers->update($mcpServer, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('MCP server updated.')]);

        return to_route('mcp-servers.index');
    }

    /**
     * Disconnect an MCP server.
     */
    public function destroy(McpServer $mcpServer): RedirectResponse
    {
        Gate::authorize('delete', $mcpServer);

        $this->servers->delete($mcpServer);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('MCP server removed.')]);

        return to_route('mcp-servers.index');
    }
}
