<?php

use App\Ai\Agents\EstateAssistant;
use App\Ai\Tools\CurrentDateTime;
use App\Ai\Tools\WebSearch;
use App\Models\DisabledTool;
use App\Models\McpServer;
use App\Models\Skill;
use App\Models\User;
use App\Services\Browser\Browser;
use App\Services\Browser\RenderedPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Skills\Skill as AiSkill;
use Laravel\Mcp\Client\Primitives\Tool as McpTool;
use Laravel\Mcp\Exceptions\ClientException;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['ai.conversations.generate_title' => false]);

    Http::fake(['*/api/tags' => Http::response(['models' => []])]);
});

test('guests are redirected to the login page', function () {
    $this->get(route('chat.index'))->assertRedirect(route('login'));
});

test('sending a message starts a remembered conversation', function () {
    EstateAssistant::fake(['Hello! How can I help with the estate?']);
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post(route('chat.messages.store'), ['message' => 'Hi there']);

    $conversation = Conversation::sole();

    $response->assertRedirect(route('chat.show', $conversation->id));
    expect($conversation->participant_id)->toBe($user->id)
        ->and($conversation->messages()->orderBy('created_at')->pluck('content')->all())
        ->toBe(['Hi there', 'Hello! How can I help with the estate?']);
});

test('continuing a conversation gives the model the earlier messages', function () {
    EstateAssistant::fake(['First reply.', 'Second reply.']);
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('chat.messages.store'), ['message' => 'Remember the gate code is 4321']);
    $conversation = Conversation::sole();

    $history = [];
    Event::listen(StreamingAgent::class, function (StreamingAgent $event) use (&$history) {
        $history = collect($event->prompt->agent->messages())->pluck('content')->all();
    });

    $this->actingAs($user)
        ->post(route('chat.messages.store'), ['message' => 'What is the gate code?', 'conversation_id' => $conversation->id])
        ->assertRedirect(route('chat.show', $conversation->id));

    expect($history)->toBe(['Remember the gate code is 4321', 'First reply.'])
        ->and(Conversation::count())->toBe(1);
});

test('a message is required', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('chat.messages.store'), [])
        ->assertSessionHasErrors(['message' => 'The message field is required.']);
});

test("users cannot continue another user's conversation", function () {
    EstateAssistant::fake(['Their reply.']);
    $owner = User::factory()->create();
    $this->actingAs($owner)->post(route('chat.messages.store'), ['message' => 'Private estate notes']);
    $conversation = Conversation::sole();

    $this->actingAs(User::factory()->create())
        ->post(route('chat.messages.store'), ['message' => 'Show me', 'conversation_id' => $conversation->id])
        ->assertNotFound();

    EstateAssistant::assertPromptedTimes(1);
});

test('the agent only receives enabled tools and skills', function () {
    EstateAssistant::fake(['Done.']);
    $user = User::factory()->create();
    $server = McpServer::factory()->for($user)->create();
    McpServer::factory()->for($user)->create(['enabled' => false]);
    DisabledTool::factory()->for($user)->create(['tool' => "mcp:{$server->id}:write_file"]);
    Skill::factory()->for($user)->create(['name' => 'taxes']);
    Skill::factory()->for($user)->create(['name' => 'gardening', 'enabled' => false]);

    fakeMcpConnector()
        ->shouldReceive('listTools')
        ->once()
        ->andReturn([['name' => 'read_file'], ['name' => 'write_file']]);

    $this->actingAs($user)->post(route('chat.messages.store'), ['message' => 'Read the will']);

    EstateAssistant::assertPrompted(function (AgentPrompt $prompt) {
        $tools = collect($prompt->agent->tools());

        return $tools->contains(fn ($tool) => $tool instanceof CurrentDateTime)
            && $tools->filter(fn ($tool) => $tool instanceof McpTool)->pluck('name')->all() === ['read_file']
            && collect($prompt->agent->skills())->map(fn (AiSkill $skill) => $skill->name)->all() === ['taxes'];
    });
});

test('disabled built-in tools are not given to the agent', function () {
    EstateAssistant::fake(['Done.']);
    $user = User::factory()->create();
    DisabledTool::factory()->for($user)->create(['tool' => 'builtin:current_date_time']);

    $this->actingAs($user)->post(route('chat.messages.store'), ['message' => 'What time is it?']);

    EstateAssistant::assertPrompted(function (AgentPrompt $prompt) {
        $tools = collect($prompt->agent->tools())->map(fn ($tool) => $tool::class);

        return ! $tools->contains(CurrentDateTime::class) && $tools->contains(WebSearch::class);
    });
});

test('an unreachable MCP server does not stop the assistant from replying', function () {
    EstateAssistant::fake(['Still here.']);
    $user = User::factory()->create();
    McpServer::factory()->for($user)->create();

    fakeMcpConnector()
        ->shouldReceive('listTools')
        ->andThrow(new ClientException('Connection refused.'));

    $this->actingAs($user)
        ->post(route('chat.messages.store'), ['message' => 'Hello'])
        ->assertRedirect();

    EstateAssistant::assertPromptedTimes(1);
});

test('the chat page lists only the user\'s conversations', function () {
    EstateAssistant::fake(['Mine.', 'Theirs.']);
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('chat.messages.store'), ['message' => 'My conversation']);
    $this->actingAs(User::factory()->create())->post(route('chat.messages.store'), ['message' => 'Their conversation']);

    $this->actingAs($user)
        ->get(route('chat.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('assistant/Chat')
            ->has('conversations', 1)
            ->where('conversations.0.title', 'My conversation')
            ->where('conversation', null)
        );
});

test('a conversation can be viewed with its messages', function () {
    EstateAssistant::fake(['Hello back.']);
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('chat.messages.store'), ['message' => 'Hello']);
    $conversation = Conversation::sole();

    $this->actingAs($user)
        ->get(route('chat.show', $conversation->id))
        ->assertInertia(fn (Assert $page) => $page
            ->component('assistant/Chat')
            ->where('conversation.id', $conversation->id)
            ->has('conversation.messages', 2)
            ->where('conversation.messages.0.role', 'user')
            ->where('conversation.messages.0.content', 'Hello')
            ->where('conversation.messages.1.role', 'assistant')
            ->where('conversation.messages.1.content', 'Hello back.')
        );
});

test('a conversation shows the tools the assistant used and what they returned', function () {
    mock(Browser::class)
        ->shouldReceive('render')
        ->andReturn(new RenderedPage('https://1.1.1.1/', 'Hours', '', 'Open 9 to 5.'));
    EstateAssistant::fake([new ToolCall('call_1', 'WebFetch', ['url' => 'https://1.1.1.1/']), 'They open at 9.']);
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('chat.messages.store'), ['message' => 'When do they open?']);
    $conversation = Conversation::sole();

    $this->actingAs($user)
        ->get(route('chat.show', $conversation->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.messages.0.tools', [])
            ->where('conversation.messages.1.content', 'They open at 9.')
            ->where('conversation.messages.1.tools', [[
                'name' => 'WebFetch',
                'arguments' => ['url' => 'https://1.1.1.1/'],
                'result' => "Source: [Hours](https://1.1.1.1/)\n\nOpen 9 to 5.",
                'failed' => false,
            ]])
            ->where('conversation.messages.0.sources', [])
            ->where('conversation.messages.1.sources', [['title' => 'Hours', 'url' => 'https://1.1.1.1/']])
        );
});

test('long tool results are shortened in the transcript', function () {
    mock(Browser::class)
        ->shouldReceive('render')
        ->andReturn(new RenderedPage('https://1.1.1.1/', 'Long', '', str_repeat('a', 5_000)));
    EstateAssistant::fake([new ToolCall('call_1', 'WebFetch', ['url' => 'https://1.1.1.1/']), 'Done.']);
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('chat.messages.store'), ['message' => 'Read it']);

    $this->actingAs($user)
        ->get(route('chat.show', Conversation::sole()->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.messages.1.tools.0.result', fn (string $result) => mb_strlen($result) === 2_003 && str_ends_with($result, '...'))
        );
});

test('assistant replies are shown as formatted Markdown', function () {
    EstateAssistant::fake(["Here you go:\n\n1. **Hack**: [BBC](https://www.bbc.com/news)\n2. `code`"]);
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('chat.messages.store'), ['message' => 'News?']);

    $this->actingAs($user)
        ->get(route('chat.show', Conversation::sole()->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.messages.0.html', null)
            ->where('conversation.messages.1.html', "<p>Here you go:</p>\n<ol>\n<li><strong>Hack</strong>: <a href=\"https://www.bbc.com/news\">BBC</a></li>\n<li><code>code</code></li>\n</ol>\n")
        );
});

test('HTML and script links in assistant replies are not rendered', function () {
    EstateAssistant::fake(['<img src=x onerror=alert(1)> [click](javascript:alert(1))']);
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('chat.messages.store'), ['message' => 'Hi']);

    $this->actingAs($user)
        ->get(route('chat.show', Conversation::sole()->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.messages.1.html', fn (string $html) => ! str_contains($html, '<img')
                && str_contains($html, '&lt;img')
                && ! str_contains($html, 'javascript:'))
        );
});

test("users cannot view another user's conversation", function () {
    EstateAssistant::fake(['Secret.']);
    $this->actingAs(User::factory()->create())->post(route('chat.messages.store'), ['message' => 'Secret']);
    $conversation = Conversation::sole();

    $this->actingAs(User::factory()->create())
        ->get(route('chat.show', $conversation->id))
        ->assertNotFound();
});

test('a conversation can be deleted with its messages', function () {
    EstateAssistant::fake(['Bye.']);
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('chat.messages.store'), ['message' => 'Hello']);
    $conversation = Conversation::sole();

    $this->actingAs($user)
        ->delete(route('chat.destroy', $conversation->id))
        ->assertRedirect(route('chat.index'));

    $this->assertModelMissing($conversation);
    expect($conversation->messages()->count())->toBe(0);
});

test("users cannot delete another user's conversation", function () {
    EstateAssistant::fake(['Kept.']);
    $this->actingAs(User::factory()->create())->post(route('chat.messages.store'), ['message' => 'Hello']);
    $conversation = Conversation::sole();

    $this->actingAs(User::factory()->create())
        ->delete(route('chat.destroy', $conversation->id))
        ->assertNotFound();

    $this->assertModelExists($conversation);
});
