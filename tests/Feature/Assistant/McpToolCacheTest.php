<?php

use App\Ai\Agents\EstateAssistant;
use App\Models\McpServer;
use App\Models\User;
use App\Services\Mcp\ClientMcpConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Mcp\Client\Primitives\Tool as McpTool;
use Laravel\Mcp\Exceptions\ClientException;

uses(RefreshDatabase::class);

beforeEach(function () {
    EstateAssistant::fake(fn () => 'Done.');
});

test("a server's tools are listed once and reused for later messages", function () {
    $user = User::factory()->create();
    McpServer::factory()->for($user)->create();

    fakeMcpConnector()->shouldReceive('listTools')->once()->andReturn([['name' => 'read_file']]);

    $this->actingAs($user)->post(route('chat.messages.store'), ['message' => 'First']);
    $this->actingAs($user)->post(route('chat.messages.store'), ['message' => 'Second']);

    EstateAssistant::assertPrompted(fn (AgentPrompt $prompt) => $prompt->prompt === 'Second'
        && collect($prompt->agent->tools())->contains(fn ($tool) => $tool instanceof McpTool && $tool->name === 'read_file'));
});

test("a server's tools are listed again once the cache expires", function () {
    config(['assistant.mcp.tool_cache_seconds' => 60]);
    $user = User::factory()->create();
    McpServer::factory()->for($user)->create();

    fakeMcpConnector()->shouldReceive('listTools')->twice()->andReturn([['name' => 'read_file']]);

    $this->actingAs($user)->post(route('chat.messages.store'), ['message' => 'First']);
    $this->travel(61)->seconds();
    $this->actingAs($user)->post(route('chat.messages.store'), ['message' => 'Second']);
});

test('updating a server lists its tools again', function () {
    $user = User::factory()->create();
    $server = McpServer::factory()->for($user)->create();

    fakeMcpConnector()->shouldReceive('listTools')->twice()->andReturn([['name' => 'read_file']]);

    $this->actingAs($user)->get(route('tools.index'));
    $this->travel(1)->seconds();
    $this->actingAs($user)->put(route('mcp-servers.update', $server), [
        'name' => $server->name,
        'transport' => 'http',
        'url' => 'https://example.com/mcp',
    ]);
    $this->actingAs($user)->get(route('tools.index'));
});

test('a server that could not be reached is tried again on the next message', function () {
    $user = User::factory()->create();
    McpServer::factory()->for($user)->create();

    fakeMcpConnector()->shouldReceive('listTools')->twice()->andThrow(new ClientException('Connection refused.'));

    $this->actingAs($user)->post(route('chat.messages.store'), ['message' => 'First']);
    $this->actingAs($user)->post(route('chat.messages.store'), ['message' => 'Second']);
});

test("a server's tools can be refreshed", function () {
    $user = User::factory()->create();
    $server = McpServer::factory()->for($user)->create();

    fakeMcpConnector()->shouldReceive('listTools')->twice()->andReturn([['name' => 'read_file']]);

    $this->actingAs($user)->get(route('tools.index'));
    $this->actingAs($user)
        ->post(route('mcp-servers.refresh', $server))
        ->assertRedirect(route('tools.index'));
    $this->actingAs($user)->get(route('tools.index'));
});

test("users cannot refresh another user's server", function () {
    $server = McpServer::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('mcp-servers.refresh', $server))
        ->assertNotFound();
});

test('cached tools are bound to the server without connecting to it', function () {
    $server = McpServer::factory()->make(['transport' => 'stdio', 'command' => '/nonexistent/mcp-server', 'args' => [], 'timeout' => 1]);

    $tools = app(ClientMcpConnector::class)->bind($server, [[
        'name' => 'read_file',
        'description' => 'Read a file.',
        'inputSchema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]],
    ]]);

    expect($tools->sole())
        ->toBeInstanceOf(McpTool::class)
        ->name->toBe('read_file')
        ->description->toBe('Read a file.')
        ->inputSchema->toBe(['type' => 'object', 'properties' => ['path' => ['type' => 'string']]]);

    expect(fn () => $tools->sole()->call(['path' => 'will.pdf']))->toThrow(ClientException::class);
});
