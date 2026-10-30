<?php

use App\Models\DisabledTool;
use App\Models\McpServer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Mcp\Exceptions\ClientException;

uses(RefreshDatabase::class);

/**
 * @return array{name: string, description: string}
 */
function mcpTool(string $name, string $description = 'An MCP tool.'): array
{
    return ['name' => $name, 'description' => $description];
}

test('guests are redirected to the login page', function () {
    $this->get(route('tools.index'))->assertRedirect(route('login'));
});

test('the tools page lists built-in tools and tools from enabled MCP servers', function () {
    $user = User::factory()->create();
    $server = McpServer::factory()->for($user)->create(['name' => 'files']);
    McpServer::factory()->for($user)->create(['enabled' => false]);
    DisabledTool::factory()->for($user)->create(['tool' => "mcp:{$server->id}:write_file"]);

    fakeMcpConnector()
        ->shouldReceive('listTools')
        ->once()
        ->withArgs(fn (McpServer $connected) => $connected->is($server))
        ->andReturn([mcpTool('read_file'), mcpTool('write_file')]);

    $this->actingAs($user)
        ->get(route('tools.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('assistant/Tools')
            ->where('builtin.0.key', 'builtin:current_date_time')
            ->where('builtin.0.enabled', true)
            ->has('servers', 1)
            ->where('servers.0.name', 'files')
            ->where('servers.0.error', null)
            ->where('servers.0.tools.0.key', "mcp:{$server->id}:read_file")
            ->where('servers.0.tools.0.enabled', true)
            ->where('servers.0.tools.1.key', "mcp:{$server->id}:write_file")
            ->where('servers.0.tools.1.enabled', false)
        );
});

test('an unreachable MCP server is reported without breaking the page', function () {
    $user = User::factory()->create();
    McpServer::factory()->for($user)->create();

    fakeMcpConnector()
        ->shouldReceive('listTools')
        ->andThrow(new ClientException('Connection refused.'));

    $this->actingAs($user)
        ->get(route('tools.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('servers.0.error', 'Connection refused.')
            ->has('servers.0.tools', 0)
        );
});

test('a tool can be disabled and re-enabled', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('tools.update'), ['tool' => 'builtin:current_date_time', 'enabled' => false])
        ->assertRedirect(route('tools.index'));

    expect($user->disabledTools()->pluck('tool')->all())->toBe(['builtin:current_date_time']);

    $this->actingAs($user)
        ->put(route('tools.update'), ['tool' => 'builtin:current_date_time', 'enabled' => true])
        ->assertRedirect(route('tools.index'));

    expect($user->disabledTools()->count())->toBe(0);
});

test('a tool on one of the user\'s MCP servers can be disabled', function () {
    $user = User::factory()->create();
    $server = McpServer::factory()->for($user)->create();

    $this->actingAs($user)
        ->put(route('tools.update'), ['tool' => "mcp:{$server->id}:read_file", 'enabled' => false])
        ->assertSessionHasNoErrors();

    expect($user->disabledTools()->pluck('tool')->all())->toBe(["mcp:{$server->id}:read_file"]);
});

test('unknown tools are rejected', function (string $tool) {
    $this->actingAs(User::factory()->create())
        ->put(route('tools.update'), ['tool' => $tool, 'enabled' => false])
        ->assertSessionHasErrors(['tool' => 'The selected tool is invalid.']);
})->with([
    'unknown built-in' => 'builtin:launch_rockets',
    'malformed key' => 'read_file',
]);

test("tools on another user's MCP server are rejected", function () {
    $server = McpServer::factory()->create();

    $this->actingAs(User::factory()->create())
        ->put(route('tools.update'), ['tool' => "mcp:{$server->id}:read_file", 'enabled' => false])
        ->assertSessionHasErrors(['tool' => 'The selected tool is invalid.']);
});
