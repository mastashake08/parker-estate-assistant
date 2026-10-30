<?php

use App\Enums\McpTransport;
use App\Models\DisabledTool;
use App\Models\McpServer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $this->get(route('mcp-servers.index'))->assertRedirect(route('login'));
});

test('users only see their own MCP servers', function () {
    $user = User::factory()->create();
    $server = McpServer::factory()->for($user)->create();
    McpServer::factory()->create();

    $this->actingAs($user)
        ->get(route('mcp-servers.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('assistant/McpServers')
            ->has('servers', 1)
            ->where('servers.0.id', $server->id)
        );
});

test('a stdio MCP server can be added', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('mcp-servers.store'), [
            'name' => 'filesystem',
            'transport' => 'stdio',
            'command' => 'npx',
            'args' => ['-y', '@modelcontextprotocol/server-filesystem', '/tmp'],
            'env' => ['DEBUG' => 'true'],
        ])
        ->assertRedirect(route('mcp-servers.index'));

    $server = $user->mcpServers()->sole();

    expect($server->name)->toBe('filesystem')
        ->and($server->transport)->toBe(McpTransport::Stdio)
        ->and($server->args)->toBe(['-y', '@modelcontextprotocol/server-filesystem', '/tmp'])
        ->and($server->env)->toBe(['DEBUG' => 'true'])
        ->and($server->enabled)->toBeTrue();
});

test('an http MCP server can be added', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('mcp-servers.store'), [
            'name' => 'remote',
            'transport' => 'http',
            'url' => 'https://mcp.example.com/mcp',
            'headers' => ['Authorization' => 'Bearer secret'],
        ])
        ->assertRedirect(route('mcp-servers.index'));

    $server = $user->mcpServers()->sole();

    expect($server->transport)->toBe(McpTransport::Http)
        ->and($server->url)->toBe('https://mcp.example.com/mcp')
        ->and($server->headers)->toBe(['Authorization' => 'Bearer secret']);
});

test('environment variables and headers are encrypted at rest', function () {
    $server = McpServer::factory()->create([
        'env' => ['API_KEY' => 'super-secret-env'],
        'headers' => ['Authorization' => 'super-secret-header'],
    ]);

    $row = DB::table('mcp_servers')->find($server->id);

    expect($row->env)->not->toContain('super-secret-env')
        ->and($row->headers)->not->toContain('super-secret-header');
});

test('name and transport are required', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('mcp-servers.store'), [])
        ->assertSessionHasErrors([
            'name' => 'The name field is required.',
            'transport' => 'The transport field is required.',
        ]);
});

test('a stdio server requires a command', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('mcp-servers.store'), ['name' => 'local', 'transport' => 'stdio'])
        ->assertSessionHasErrors(['command' => 'The command field is required when transport is stdio.']);
});

test('an http server requires a valid url', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('mcp-servers.store'), ['name' => 'remote', 'transport' => 'http', 'url' => 'not-a-url'])
        ->assertSessionHasErrors(['url' => 'The url field must be a valid URL.']);
});

test('server names are unique per user', function () {
    $user = User::factory()->create();
    McpServer::factory()->for($user)->create(['name' => 'filesystem']);
    McpServer::factory()->create(['name' => 'shared-name']);

    $this->actingAs($user)
        ->post(route('mcp-servers.store'), ['name' => 'filesystem', 'transport' => 'stdio', 'command' => 'npx'])
        ->assertSessionHasErrors(['name' => 'The name has already been taken.']);

    $this->actingAs($user)
        ->post(route('mcp-servers.store'), ['name' => 'shared-name', 'transport' => 'stdio', 'command' => 'npx'])
        ->assertSessionHasNoErrors();
});

test('stdio servers are rejected when local commands are disabled', function () {
    config(['assistant.mcp.stdio_enabled' => false]);

    $this->actingAs(User::factory()->create())
        ->post(route('mcp-servers.store'), ['name' => 'local', 'transport' => 'stdio', 'command' => 'npx'])
        ->assertSessionHasErrors(['transport' => 'Local (stdio) MCP servers are disabled.']);
});

test('a server can be updated and disabled', function () {
    $user = User::factory()->create();
    $server = McpServer::factory()->for($user)->create(['name' => 'old']);

    $this->actingAs($user)
        ->put(route('mcp-servers.update', $server), [
            'name' => 'new',
            'transport' => 'stdio',
            'command' => 'uvx',
            'enabled' => false,
        ])
        ->assertRedirect(route('mcp-servers.index'));

    $server->refresh();

    expect($server->name)->toBe('new')
        ->and($server->command)->toBe('uvx')
        ->and($server->enabled)->toBeFalse();
});

test("users cannot update another user's server", function () {
    $server = McpServer::factory()->create(['name' => 'theirs']);

    $this->actingAs(User::factory()->create())
        ->put(route('mcp-servers.update', $server), ['name' => 'mine', 'transport' => 'stdio', 'command' => 'npx'])
        ->assertNotFound();

    expect($server->refresh()->name)->toBe('theirs');
});

test('deleting a server removes it and its tool preferences', function () {
    $user = User::factory()->create();
    $server = McpServer::factory()->for($user)->create();
    DisabledTool::factory()->for($user)->create(['tool' => "mcp:{$server->id}:read_file"]);
    DisabledTool::factory()->for($user)->create(['tool' => 'builtin:current_date_time']);

    $this->actingAs($user)
        ->delete(route('mcp-servers.destroy', $server))
        ->assertRedirect(route('mcp-servers.index'));

    $this->assertModelMissing($server);
    expect($user->disabledTools()->pluck('tool')->all())->toBe(['builtin:current_date_time']);
});

test("users cannot delete another user's server", function () {
    $server = McpServer::factory()->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('mcp-servers.destroy', $server))
        ->assertNotFound();

    $this->assertModelExists($server);
});
