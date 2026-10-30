<?php

use App\Ai\Agents\EstateAssistant;
use App\Ai\Tools\CurrentDateTime;
use App\Ai\Tools\WebSearch;
use App\Models\DisabledTool;
use App\Models\McpServer;
use App\Models\Skill;
use App\Models\User;
use App\Services\Mcp\McpConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Skills\Skill as AiSkill;
use Laravel\Mcp\Client\Primitives\Tool as McpTool;
use Laravel\Mcp\Exceptions\ClientException;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['ai.conversations.generate_title' => false]);
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

    mock(McpConnector::class)
        ->shouldReceive('tools')
        ->once()
        ->andReturn(collect([
            McpTool::from(null, ['name' => 'read_file']),
            McpTool::from(null, ['name' => 'write_file']),
        ]));

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

    mock(McpConnector::class)
        ->shouldReceive('tools')
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
