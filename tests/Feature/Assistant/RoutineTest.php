<?php

use App\Jobs\RunRoutine;
use App\Models\McpServer;
use App\Models\Routine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::fake(['*/api/tags' => Http::response(['models' => [['name' => 'llama3.2:3b']]])]);

    // A Wednesday morning in New York.
    $this->travelTo('2026-11-04 12:00:00');
});

/**
 * A valid routine form, with any fields replaced.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function routineForm(array $overrides = []): array
{
    return [
        'name' => 'Morning brief',
        'instructions' => 'Give me the top news and the weather in Louisville.',
        'frequency' => 'daily',
        'time' => '08:00',
        'timezone' => 'America/New_York',
        'tools' => ['builtin:web_search', 'builtin:send_email'],
        'auto_approve' => false,
        'deliver_email' => true,
        'deliver_sms' => false,
        ...$overrides,
    ];
}

test('guests are redirected to the login page', function () {
    $this->get(route('routines.index'))->assertRedirect(route('login'));
});

test('users only see their own routines, described in words', function () {
    $user = User::factory()->create();
    Routine::factory()->for($user)->create(['name' => 'mine']);
    Routine::factory()->create(['name' => 'theirs']);

    $this->actingAs($user)
        ->get(route('routines.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('assistant/Routines')
            ->has('routines', 1)
            ->where('routines.0.name', 'mine')
            ->where('routines.0.schedule', 'Every day at 8:00 AM')
            ->where('tools.builtin', fn ($tools) => collect($tools)->firstWhere('key', 'builtin:send_email')['needsApproval'] === true
                && collect($tools)->firstWhere('key', 'builtin:web_search')['needsApproval'] === false)
            ->where('models', ['llama3.2:3b'])
        );
});

test('the page offers the timezones the server accepts', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('routines.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('timezones', DateTimeZone::listIdentifiers())
            ->where('timezones', fn ($zones) => collect($zones)->contains('America/Kentucky/Louisville'))
        );
});

test('browser-only timezone names are rejected', function () {
    // Browsers name some zones by aliases PHP does not know, such as
    // "America/Louisville" for "America/Kentucky/Louisville".
    $this->actingAs(User::factory()->create())
        ->post(route('routines.store'), routineForm(['timezone' => 'America/Louisville']))
        ->assertSessionHasErrors('timezone');
});

test('a routine is scheduled for its next run in its timezone', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('routines.store'), routineForm())
        ->assertRedirect(route('routines.index'));

    $routine = Routine::sole();

    // 8:00 AM in New York on November 4 is 13:00 UTC (standard time).
    expect($routine->user_id)->toBe($user->id)
        ->and($routine->cron)->toBe('0 8 * * *')
        ->and($routine->next_run_at->toDateTimeString())->toBe('2026-11-04 13:00:00')
        ->and($routine->tools)->toBe(['builtin:web_search', 'builtin:send_email']);
});

test('each frequency becomes a cron expression and a description', function (array $schedule, string $cron, string $description) {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('routines.store'), routineForm($schedule));

    $routine = Routine::sole();

    expect($routine->cron)->toBe($cron);

    $this->actingAs($user)
        ->get(route('routines.index'))
        ->assertInertia(fn (Assert $page) => $page->where('routines.0.schedule', $description));
})->with([
    'daily' => [['frequency' => 'daily', 'time' => '07:30'], '30 7 * * *', 'Every day at 7:30 AM'],
    'weekdays' => [['frequency' => 'weekdays', 'time' => '18:05'], '5 18 * * 1-5', 'Weekdays at 6:05 PM'],
    'weekly' => [['frequency' => 'weekly', 'time' => '09:00', 'day_of_week' => 1], '0 9 * * 1', 'Every Monday at 9:00 AM'],
    'every hour' => [['frequency' => 'hourly', 'time' => null, 'every_hours' => 1], '0 */1 * * *', 'Every hour'],
    'every few hours' => [['frequency' => 'hourly', 'time' => null, 'every_hours' => 3], '0 */3 * * *', 'Every 3 hours'],
    'custom' => [['frequency' => 'custom', 'time' => null, 'cron' => '15 6 1 * *'], '15 6 1 * *', 'Cron: 15 6 1 * *'],
]);

test('the schedule fields each frequency needs are required', function (array $schedule, string $field) {
    $this->actingAs(User::factory()->create())
        ->post(route('routines.store'), routineForm($schedule))
        ->assertSessionHasErrors($field);
})->with([
    'a time for daily' => [['frequency' => 'daily', 'time' => null], 'time'],
    'a valid time' => [['frequency' => 'daily', 'time' => '25:00'], 'time'],
    'a day for weekly' => [['frequency' => 'weekly', 'day_of_week' => null], 'day_of_week'],
    'hours for hourly' => [['frequency' => 'hourly', 'every_hours' => null], 'every_hours'],
    'a cron expression for custom' => [['frequency' => 'custom', 'cron' => null], 'cron'],
    'a valid cron expression' => [['frequency' => 'custom', 'cron' => 'every morning'], 'cron'],
    'a real timezone' => [['timezone' => 'Mars/Olympus'], 'timezone'],
]);

test('routines can only use tools the user has', function () {
    $user = User::factory()->create();
    $mine = McpServer::factory()->for($user)->create();
    $theirs = McpServer::factory()->create();

    $this->actingAs($user)
        ->post(route('routines.store'), routineForm(['tools' => ["mcp:{$theirs->id}:read_file"]]))
        ->assertSessionHasErrors('tools.0');

    $this->actingAs($user)
        ->post(route('routines.store'), routineForm(['tools' => ['builtin:launch_rockets']]))
        ->assertSessionHasErrors('tools.0');

    $this->actingAs($user)
        ->post(route('routines.store'), routineForm(['tools' => ["mcp:{$mine->id}:read_file"]]))
        ->assertSessionHasNoErrors();
});

test('texting the result needs a number in international format', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('routines.store'), routineForm(['deliver_sms' => true, 'sms_to' => null]))
        ->assertSessionHasErrors(['sms_to' => 'Enter the number to text the result to.']);

    $this->actingAs($user)
        ->post(route('routines.store'), routineForm(['deliver_sms' => true, 'sms_to' => '502-555-0123']))
        ->assertSessionHasErrors(['sms_to' => 'Enter the number in international format, such as +15025550123.']);
});

test('routine names are unique per user', function () {
    $user = User::factory()->create();
    Routine::factory()->for($user)->create(['name' => 'Morning brief']);
    Routine::factory()->create(['name' => 'Evening brief']);

    $this->actingAs($user)->post(route('routines.store'), routineForm())->assertSessionHasErrors('name');
    $this->actingAs($user)->post(route('routines.store'), routineForm(['name' => 'Evening brief']))->assertSessionHasNoErrors();
});

test('updating a routine works out its next run again', function () {
    $user = User::factory()->create();
    $routine = Routine::factory()->for($user)->create();

    $this->actingAs($user)
        ->put(route('routines.update', $routine), routineForm(['frequency' => 'weekly', 'day_of_week' => 5, 'time' => '17:00']))
        ->assertRedirect(route('routines.index'));

    $routine->refresh();

    // Friday at 5:00 PM in New York.
    expect($routine->cron)->toBe('0 17 * * 5')
        ->and($routine->next_run_at->toDateTimeString())->toBe('2026-11-06 22:00:00');
});

test('a routine can be deleted', function () {
    $user = User::factory()->create();
    $routine = Routine::factory()->for($user)->create();

    $this->actingAs($user)
        ->delete(route('routines.destroy', $routine))
        ->assertRedirect(route('routines.index'));

    $this->assertModelMissing($routine);
});

test('a routine can be run now without changing its schedule', function () {
    Queue::fake();
    $user = User::factory()->create();
    $routine = Routine::factory()->for($user)->create();
    $nextRun = $routine->next_run_at;

    $this->actingAs($user)
        ->post(route('routines.runs.store', $routine))
        ->assertRedirect(route('routines.index'));

    Queue::assertPushed(RunRoutine::class, fn (RunRoutine $job) => $job->routine->is($routine));
    expect($routine->refresh()->next_run_at->equalTo($nextRun))->toBeTrue();
});

test("users cannot change, delete or run another user's routine", function () {
    Queue::fake();
    $routine = Routine::factory()->create(['name' => 'theirs']);
    $this->actingAs(User::factory()->create());

    $this->put(route('routines.update', $routine), routineForm())->assertNotFound();
    $this->delete(route('routines.destroy', $routine))->assertNotFound();
    $this->post(route('routines.runs.store', $routine))->assertNotFound();

    expect($routine->refresh()->name)->toBe('theirs');
    Queue::assertNothingPushed();
});
