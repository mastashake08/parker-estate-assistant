<?php

use App\Ai\Agents\EstateAssistant;
use App\Events\AssistantReplyStreamed;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    Http::fake(['*/api/tags' => Http::response(['models' => []])]);

    EstateAssistant::fake([
        new ToolCall('call_1', 'SendEmail', ['to' => 'jane@example.com', 'subject' => 'Gate code', 'body' => 'It is 4321.']),
        'Done, I emailed Jane.',
    ]);

    $this->user = User::factory()->create();
    $this->actingAs($this->user)->post(route('chat.messages.store'), ['message' => 'Email Jane the gate code']);
    $this->conversation = Conversation::sole();
});

test('a reply that wants to send an email waits for approval', function () {
    Mail::assertNothingSent();

    $this->actingAs($this->user)
        ->get(route('chat.show', $this->conversation->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.approvals', [[
                'id' => 'call_1',
                'tool' => 'SendEmail',
                'arguments' => ['to' => 'jane@example.com', 'subject' => 'Gate code', 'body' => 'It is 4321.'],
                'reason' => 'Send an email to jane@example.com: "Gate code"',
            ]])
        );
});

// The SDK does not run approved tools while the agent is faked, so these tests
// check the decision reaches the agent; MessagingToolsTest covers the sending.

test('approving resumes the reply with the tool call approved', function () {
    $this->actingAs($this->user)
        ->post(route('chat.approvals.store', $this->conversation->id), ['decisions' => ['call_1' => true]])
        ->assertRedirect(route('chat.show', $this->conversation->id))
        ->assertInertiaFlash('resumingReply', true);

    EstateAssistant::assertPrompted(fn (AgentPrompt $prompt) => $prompt->approvalDecisions?->all()['call_1']->isApproved());

    $this->actingAs($this->user)
        ->get(route('chat.show', $this->conversation->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.approvals', [])
            ->where('conversation.messages.1.content', 'Done, I emailed Jane.')
        );
});

test('denying resumes the reply with the tool call rejected', function () {
    $this->actingAs($this->user)
        ->post(route('chat.approvals.store', $this->conversation->id), ['decisions' => ['call_1' => false]])
        ->assertRedirect(route('chat.show', $this->conversation->id));

    EstateAssistant::assertPrompted(fn (AgentPrompt $prompt) => $prompt->approvalDecisions?->all()['call_1']->isRejected());
    Mail::assertNothingSent();
});

test('decisions must answer exactly the requests waiting for approval', function (array $decisions) {
    $this->actingAs($this->user)
        ->post(route('chat.approvals.store', $this->conversation->id), ['decisions' => $decisions])
        ->assertSessionHasErrors(['decisions' => 'Approve or deny each request that is waiting for approval.']);

    Mail::assertNothingSent();
})->with([
    'unknown request' => [['call_999' => true]],
    'extra request' => [['call_1' => true, 'call_999' => true]],
]);

test('decisions are required and must be yes or no', function (mixed $decisions) {
    $this->actingAs($this->user)
        ->post(route('chat.approvals.store', $this->conversation->id), ['decisions' => $decisions])
        ->assertSessionHasErrors('decisions');
})->with([
    'missing' => [null],
    'empty' => [[]],
]);

test('each decision must be yes or no', function () {
    $this->actingAs($this->user)
        ->post(route('chat.approvals.store', $this->conversation->id), ['decisions' => ['call_1' => 'maybe']])
        ->assertSessionHasErrors('decisions.call_1');
});

test("another user's tool calls cannot be approved", function () {
    $this->actingAs(User::factory()->create())
        ->post(route('chat.approvals.store', $this->conversation->id), ['decisions' => ['call_1' => true]])
        ->assertNotFound();

    Mail::assertNothingSent();
});

test('the chat is told live when a tool call needs approval', function () {
    EstateAssistant::fake([new ToolCall('call_2', 'SendEmail', ['to' => 'bob@example.com', 'subject' => 'Hi', 'body' => 'Hi'])]);
    Event::fake([AssistantReplyStreamed::class]);

    $this->actingAs($this->user)->post(route('chat.messages.store'), ['message' => 'Email Bob']);

    $types = Event::dispatched(AssistantReplyStreamed::class)->map(fn (array $arguments) => $arguments[0]->event['type']);

    expect($types)->toContain('tool_approval_request')
        ->and($types->last())->toBe('reply_complete');
});

test('the approval shows the arguments a model wrapped in a copy of the schema', function () {
    EstateAssistant::fake([new ToolCall('call_3', 'SendSms', ['type' => 'object', 'properties' => ['to' => '+15025550123', 'message' => 'Hi']])]);
    $this->actingAs($this->user)->post(route('chat.messages.store'), ['message' => 'Text Jane']);

    $this->actingAs($this->user)
        ->get(route('chat.show', Conversation::latest('created_at')->latest('id')->first()->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.approvals.0.arguments', ['to' => '+15025550123', 'message' => 'Hi'])
            ->where('conversation.approvals.0.reason', 'Send a text message to +15025550123')
        );
});
