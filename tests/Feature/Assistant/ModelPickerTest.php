<?php

use App\Ai\Agents\EstateAssistant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Prompts\AgentPrompt;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'assistant.provider' => 'ollama',
        'assistant.model' => 'llama3.2:1b',
        'ai.providers.ollama.url' => 'http://ollama.test',
    ]);

    Http::fake([
        'ollama.test/api/tags' => Http::response(['models' => [
            ['name' => 'llama3.2:1b'],
            ['name' => 'qwen2.5:7b'],
        ]]),
    ]);

    EstateAssistant::fake(fn () => 'Done.');
});

test('the chat page lists the installed models and the default', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('chat.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('models', ['llama3.2:1b', 'qwen2.5:7b'])
            ->where('defaultModel', 'llama3.2:1b')
        );
});

test('a new conversation replies with the chosen model and remembers it', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('chat.messages.store'), ['message' => 'Hello', 'model' => 'qwen2.5:7b']);

    $conversation = Conversation::sole();
    expect($conversation->model)->toBe('qwen2.5:7b');
    EstateAssistant::assertPrompted(fn (AgentPrompt $prompt) => $prompt->model === 'qwen2.5:7b');

    $this->actingAs($user)
        ->get(route('chat.show', $conversation->id))
        ->assertInertia(fn (Assert $page) => $page->where('conversation.model', 'qwen2.5:7b'));
});

test('a conversation keeps its model when continued without choosing one', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('chat.messages.store'), ['message' => 'Hello', 'model' => 'qwen2.5:7b']);
    $conversation = Conversation::sole();

    $this->actingAs($user)->post(route('chat.messages.store'), ['message' => 'Again', 'conversation_id' => $conversation->id]);

    EstateAssistant::assertPrompted(fn (AgentPrompt $prompt) => $prompt->prompt === 'Again' && $prompt->model === 'qwen2.5:7b');
});

test("a conversation's model can be switched", function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('chat.messages.store'), ['message' => 'Hello', 'model' => 'qwen2.5:7b']);
    $conversation = Conversation::sole();

    $this->actingAs($user)->post(route('chat.messages.store'), [
        'message' => 'Again',
        'conversation_id' => $conversation->id,
        'model' => 'llama3.2:1b',
    ]);

    expect($conversation->refresh()->model)->toBe('llama3.2:1b');
    EstateAssistant::assertPrompted(fn (AgentPrompt $prompt) => $prompt->prompt === 'Again' && $prompt->model === 'llama3.2:1b');
});

test('the default model is used when none is chosen', function () {
    $this->actingAs(User::factory()->create())->post(route('chat.messages.store'), ['message' => 'Hello']);

    expect(Conversation::sole()->model)->toBeNull();
    EstateAssistant::assertPrompted(fn (AgentPrompt $prompt) => $prompt->model === 'llama3.2:1b');
});

test('only installed models can be chosen', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('chat.messages.store'), ['message' => 'Hello', 'model' => 'gpt-oss:120b'])
        ->assertSessionHasErrors(['model' => 'The selected model is invalid.']);

    expect(Conversation::count())->toBe(0);
});

test('the chat still works when Ollama cannot be reached', function () {
    Http::fake(['ollama.test/*' => fn () => throw new ConnectionException('Connection refused.')]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('chat.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('models', []));

    $this->actingAs($user)
        ->post(route('chat.messages.store'), ['message' => 'Hello'])
        ->assertSessionHasNoErrors();
});

test('models are not listed when the assistant does not use Ollama', function () {
    config(['assistant.provider' => 'openai']);

    $this->actingAs(User::factory()->create())
        ->get(route('chat.index'))
        ->assertInertia(fn (Assert $page) => $page->where('models', []));

    Http::assertNothingSent();
});
