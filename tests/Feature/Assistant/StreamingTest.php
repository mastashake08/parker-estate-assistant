<?php

use App\Ai\Agents\EstateAssistant;
use App\Events\AssistantReplyStreamed;
use App\Jobs\StreamAssistantReply;
use App\Models\User;
use App\Services\Browser\Browser;
use App\Services\Browser\RenderedPage;
use App\Services\ConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Responses\Data\ToolCall;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

test('sending a message starts a conversation and queues the reply', function () {
    Queue::fake([StreamAssistantReply::class]);
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post(route('chat.messages.store'), ['message' => 'What is on the calendar today?']);

    $conversation = Conversation::sole();

    $response->assertRedirect(route('chat.show', $conversation->id));
    expect($conversation->participant_id)->toBe($user->id)
        ->and($conversation->title)->toBe('What is on the calendar today?');

    Queue::assertPushed(StreamAssistantReply::class, fn (StreamAssistantReply $job) => $job->user->is($user)
        && $job->conversationId === $conversation->id
        && $job->message === 'What is on the calendar today?');
});

test('the pending message is flashed so the chat can show it before the reply is stored', function () {
    Queue::fake([StreamAssistantReply::class]);

    $this->actingAs(User::factory()->create())
        ->post(route('chat.messages.store'), ['message' => 'Hello'])
        ->assertInertiaFlash('pendingMessage', 'Hello');
});

test('long first messages are shortened for the conversation title', function () {
    Queue::fake([StreamAssistantReply::class]);

    $this->actingAs(User::factory()->create())
        ->post(route('chat.messages.store'), ['message' => str_repeat('estate ', 20)]);

    expect(Conversation::sole()->title)->toBe('estate estate estate estate estate estate estate...');
});

test('continuing a conversation queues the reply in that conversation', function () {
    EstateAssistant::fake(['First reply.']);
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('chat.messages.store'), ['message' => 'Hello']);
    $conversation = Conversation::sole();

    Queue::fake([StreamAssistantReply::class]);

    $this->actingAs($user)
        ->post(route('chat.messages.store'), ['message' => 'And again', 'conversation_id' => $conversation->id])
        ->assertRedirect(route('chat.show', $conversation->id));

    expect(Conversation::count())->toBe(1);
    Queue::assertPushed(StreamAssistantReply::class, fn (StreamAssistantReply $job) => $job->conversationId === $conversation->id);
});

test("a reply is not queued for another user's conversation", function () {
    EstateAssistant::fake(['Theirs.']);
    $this->actingAs(User::factory()->create())->post(route('chat.messages.store'), ['message' => 'Private']);
    $conversation = Conversation::sole();

    Queue::fake([StreamAssistantReply::class]);

    $this->actingAs(User::factory()->create())
        ->post(route('chat.messages.store'), ['message' => 'Show me', 'conversation_id' => $conversation->id])
        ->assertNotFound();

    Queue::assertNotPushed(StreamAssistantReply::class);
});

test('the reply is streamed to the user over their private channel and remembered', function () {
    EstateAssistant::fake(['The gate code is 4321.']);
    $user = User::factory()->create();
    $conversationId = app(ConversationService::class)->start($user, 'What is the gate code?');

    Event::fake([AssistantReplyStreamed::class]);

    StreamAssistantReply::dispatchSync($user, $conversationId, 'What is the gate code?');

    Event::assertDispatched(AssistantReplyStreamed::class, fn (AssistantReplyStreamed $event) => $event->broadcastOn()[0]->name === "private-App.Models.User.{$user->id}"
        && $event->broadcastWith()['conversation_id'] === $conversationId
        && $event->broadcastWith()['event']['type'] === 'text_delta');

    expect(Conversation::find($conversationId)->messages()->orderBy('created_at')->pluck('content')->all())
        ->toBe(['What is the gate code?', 'The gate code is 4321.']);

    $types = Event::dispatched(AssistantReplyStreamed::class)->map(fn (array $arguments) => $arguments[0]->event['type']);

    expect($types->last())->toBe('reply_complete');
});

test('the chat is told the reply is complete only after the exchange is stored', function () {
    EstateAssistant::fake(['Stored.']);
    $user = User::factory()->create();
    $conversationId = app(ConversationService::class)->start($user, 'Hello');

    $storedWhenComplete = null;
    Event::listen(AssistantReplyStreamed::class, function (AssistantReplyStreamed $event) use (&$storedWhenComplete, $conversationId) {
        if ($event->event['type'] === 'reply_complete') {
            $storedWhenComplete = Conversation::find($conversationId)->messages()->count();
        }
    });

    StreamAssistantReply::dispatchSync($user, $conversationId, 'Hello');

    expect($storedWhenComplete)->toBe(2);
});

test('tool results are broadcast without their output so large pages fit in a broadcast', function () {
    mock(Browser::class)
        ->shouldReceive('render')
        ->andReturn(new RenderedPage('https://1.1.1.1/', 'Long', '', str_repeat('a', 20_000)));
    EstateAssistant::fake([new ToolCall('call_1', 'WebFetch', ['url' => 'https://1.1.1.1/']), 'Read it.']);
    $user = User::factory()->create();
    $conversationId = app(ConversationService::class)->start($user, 'Read this page');

    Event::fake([AssistantReplyStreamed::class]);

    StreamAssistantReply::dispatchSync($user, $conversationId, 'Read this page');

    Event::assertDispatched(AssistantReplyStreamed::class, fn (AssistantReplyStreamed $event) => $event->event['type'] === 'tool_result'
        && $event->event['tool_name'] === 'WebFetch'
        && ! array_key_exists('result', $event->event));

    Event::dispatched(AssistantReplyStreamed::class)->each(
        fn (array $arguments) => expect(strlen(json_encode($arguments[0]->broadcastWith())))->toBeLessThan(10_000)
    );
});

test('a failed reply broadcasts an error so the chat stops waiting', function () {
    EstateAssistant::fake(fn () => throw new RuntimeException('Ollama is not running.'));
    $user = User::factory()->create();
    $conversationId = app(ConversationService::class)->start($user, 'Hello');

    Event::fake([AssistantReplyStreamed::class]);

    expect(fn () => StreamAssistantReply::dispatchSync($user, $conversationId, 'Hello'))
        ->toThrow(RuntimeException::class, 'Ollama is not running.');

    Event::assertDispatched(AssistantReplyStreamed::class, fn (AssistantReplyStreamed $event) => $event->broadcastWith() === [
        'conversation_id' => $conversationId,
        'event' => ['type' => 'error', 'message' => 'The assistant could not reply. Please try again.'],
    ]);
});

test("users cannot listen to another user's channel", function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);

    // Channels are registered on the broadcaster that was the default at boot...
    require base_path('routes/channels.php');

    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user)
        ->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-App.Models.User.{$other->id}"])
        ->assertForbidden();

    $this->actingAs($user)
        ->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-App.Models.User.{$user->id}"])
        ->assertOk();
});
