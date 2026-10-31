<?php

use App\Ai\Agents\EstateAssistant;
use App\Ai\Tools\CurrentDateTime;
use App\Ai\Tools\SendEmail;
use App\Ai\Tools\WebSearch;
use App\Enums\RoutineRunStatus;
use App\Jobs\RunRoutine;
use App\Mail\RoutineResult;
use App\Models\Routine;
use App\Models\User;
use App\Notifications\RoutineResultSms;
use App\Services\RoutineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;
use NotificationChannels\Twilio\TwilioChannel;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['ai.conversations.generate_title' => false]);
    Mail::fake();
    Notification::fake();

    $this->travelTo('2026-11-04 13:00:00');
});

function runRoutine(Routine $routine): void
{
    app(RoutineService::class)->run($routine);
}

test('due routines are queued and their next run moves forward', function () {
    Queue::fake();
    $due = Routine::factory()->due()->create(['timezone' => 'America/New_York']);
    $later = Routine::factory()->create();
    $paused = Routine::factory()->due()->create(['enabled' => false]);

    $this->artisan('routines:run-due')->assertSuccessful();

    Queue::assertPushed(RunRoutine::class, 1);
    Queue::assertPushed(RunRoutine::class, fn (RunRoutine $job) => $job->routine->is($due));

    // The next 8:00 AM in New York after 8:00 AM on November 4.
    expect($due->refresh()->next_run_at->toDateTimeString())->toBe('2026-11-05 13:00:00')
        ->and($due->last_run_at->toDateTimeString())->toBe('2026-11-04 13:00:00');
});

test('a due routine is only queued once', function () {
    Queue::fake();
    Routine::factory()->due()->create();

    $this->artisan('routines:run-due');
    $this->artisan('routines:run-due');

    Queue::assertPushed(RunRoutine::class, 1);
});

test('a routine another scheduler has just claimed is not queued again', function () {
    Queue::fake();
    $routine = Routine::factory()->due()->create();

    // Another scheduler moves the next run forward right after this one reads the routine.
    Routine::retrieved(fn (Routine $read) => DB::table('routines')->where('id', $read->id)->update(['next_run_at' => now()->addDay()]));

    $this->artisan('routines:run-due');

    Queue::assertNothingPushed();
});

test('the scheduler checks for due routines every minute', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('routines:run-due');
});

test('a run prompts the assistant with the instructions in a new conversation', function () {
    EstateAssistant::fake(['Here is your brief.']);
    $user = User::factory()->create();
    $routine = Routine::factory()->for($user)->create([
        'name' => 'Morning brief',
        'instructions' => 'Brief me.',
        'timezone' => 'America/New_York',
        'model' => 'llama3.2:3b',
    ]);

    runRoutine($routine);

    $conversation = Conversation::sole();
    $run = $routine->runs()->sole();

    expect($conversation->participant_id)->toBe($user->id)
        ->and($conversation->title)->toBe('Morning brief · Nov 4, 8:00 AM')
        ->and($conversation->model)->toBe('llama3.2:3b')
        ->and($conversation->messages()->orderBy('created_at')->pluck('content')->all())->toBe(['Brief me.', 'Here is your brief.'])
        ->and($run->status)->toBe(RoutineRunStatus::Completed)
        ->and($run->conversation_id)->toBe($conversation->id)
        ->and($run->finished_at)->not->toBeNull();
});

test('a run only gives the assistant the routine\'s tools', function () {
    EstateAssistant::fake(['Done.']);
    $routine = Routine::factory()->create(['tools' => ['builtin:web_search']]);

    runRoutine($routine);

    EstateAssistant::assertPrompted(fn (AgentPrompt $prompt) => collect($prompt->agent->tools())->map(fn ($tool) => $tool::class)->all() === [WebSearch::class]);
});

test('the routine\'s tools are used even if they are switched off for the chat', function () {
    EstateAssistant::fake(['Done.']);
    $routine = Routine::factory()->create(['tools' => ['builtin:current_date_time']]);
    $routine->user->disabledTools()->create(['tool' => 'builtin:current_date_time']);

    runRoutine($routine);

    EstateAssistant::assertPrompted(fn (AgentPrompt $prompt) => collect($prompt->agent->tools())->contains(fn ($tool) => $tool instanceof CurrentDateTime));
});

test('the result is emailed to the routine\'s owner', function () {
    EstateAssistant::fake(['**Sunny** and 60°F.']);
    $user = User::factory()->create(['email' => 'owner@example.com']);
    $routine = Routine::factory()->for($user)->create(['name' => 'Weather', 'deliver_email' => true]);

    runRoutine($routine);

    $conversation = Conversation::sole();

    Mail::assertSent(RoutineResult::class, fn (RoutineResult $mail) => $mail->hasTo('owner@example.com')
        && $mail->routineName === 'Weather'
        && $mail->reply === '**Sunny** and 60°F.'
        && $mail->conversationUrl === route('chat.show', $conversation->id));
    Notification::assertNothingSent();
});

test('the result is texted as plain text to the routine\'s number', function () {
    EstateAssistant::fake(["**Sunny** and 60°F.\n\n- See [the forecast](https://example.com)"]);
    $routine = Routine::factory()->create(['name' => 'Weather', 'deliver_sms' => true, 'sms_to' => '+15025550123']);

    runRoutine($routine);

    Notification::assertSentOnDemand(RoutineResultSms::class, function (RoutineResultSms $notification, array $channels, AnonymousNotifiable $notifiable) {
        $text = $notification->toTwilio($notifiable)->content;

        return $notifiable->routes[TwilioChannel::class] === '+15025550123'
            && str_starts_with($text, "Weather\n\nSunny and 60°F.")
            && str_contains($text, 'See the forecast')
            && ! str_contains($text, '**')
            && str_ends_with($text, route('chat.show', Conversation::sole()->id));
    });
    Mail::assertNothingSent();
});

test('a run that wants to send something waits for approval and says so', function () {
    EstateAssistant::fake([
        new ToolCall('call_1', 'SendEmail', ['to' => 'jane@example.com', 'subject' => 'Hi', 'body' => 'Hello.']),
        'Sent.',
    ]);
    $routine = Routine::factory()->create([
        'name' => 'Check-in', 'tools' => ['builtin:send_email'], 'auto_approve' => false, 'deliver_email' => true,
    ]);

    runRoutine($routine);

    expect($routine->runs()->sole()->status)->toBe(RoutineRunStatus::WaitingForApproval);
    EstateAssistant::assertPromptedTimes(1);
    Mail::assertSent(RoutineResult::class, fn (RoutineResult $mail) => $mail->reply === 'Check-in is waiting for you to approve what it wants to do.');
});

// The SDK does not run approved tools while the agent is faked, so this checks
// the approval reaches the agent; MessagingToolsTest covers the sending.
test('a pre-approved run approves its tool calls and carries on', function () {
    EstateAssistant::fake([
        new ToolCall('call_1', 'SendEmail', ['to' => 'jane@example.com', 'subject' => 'Hi', 'body' => 'Hello.']),
        'Sent.',
    ]);
    $routine = Routine::factory()->create(['tools' => ['builtin:send_email'], 'auto_approve' => true]);

    runRoutine($routine);

    EstateAssistant::assertPrompted(fn (AgentPrompt $prompt) => $prompt->approvalDecisions?->all()['call_1']->isApproved()
        && collect($prompt->agent->tools())->contains(fn ($tool) => $tool instanceof SendEmail));
    expect($routine->runs()->sole()->status)->toBe(RoutineRunStatus::Completed);
});

test('a failed run is recorded with its error', function () {
    EstateAssistant::fake(fn () => throw new RuntimeException('Ollama is not running.'));
    $routine = Routine::factory()->create(['deliver_email' => true]);

    expect(fn () => runRoutine($routine))->toThrow(RuntimeException::class, 'Ollama is not running.');

    $run = $routine->runs()->sole();

    expect($run->status)->toBe(RoutineRunStatus::Failed)
        ->and($run->error)->toBe('Ollama is not running.');
    Mail::assertNothingSent();
});

test('a delivery failure is recorded without failing the run', function () {
    EstateAssistant::fake(['Done.']);
    Notification::swap(new ChannelManager(app()));
    mock(TwilioChannel::class)->shouldReceive('send')->andThrow(new RuntimeException('Twilio is down.'));
    $routine = Routine::factory()->create(['deliver_sms' => true, 'sms_to' => '+15025550123']);

    runRoutine($routine);

    $run = $routine->runs()->sole();

    expect($run->status)->toBe(RoutineRunStatus::Completed)
        ->and($run->error)->toBe('Could not text the result: Twilio is down.');
});

test('the job runs the routine', function () {
    EstateAssistant::fake(['Done.']);
    $routine = Routine::factory()->create();

    RunRoutine::dispatchSync($routine);

    expect($routine->runs()->sole()->status)->toBe(RoutineRunStatus::Completed);
});
