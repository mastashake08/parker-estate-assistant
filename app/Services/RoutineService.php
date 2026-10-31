<?php

namespace App\Services;

use App\Enums\RoutineFrequency;
use App\Enums\RoutineRunStatus;
use App\Jobs\RunRoutine;
use App\Mail\RoutineResult;
use App\Models\Routine;
use App\Models\RoutineRun;
use App\Models\User;
use App\Notifications\RoutineResultSms;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use NotificationChannels\Twilio\TwilioChannel;
use Throwable;

class RoutineService
{
    /**
     * How many times a pre-approved run may resume itself after approving its tool calls.
     */
    protected const MAX_AUTO_APPROVALS = 5;

    /**
     * How many recent runs are listed for each routine.
     */
    protected const RECENT_RUNS = 5;

    public function __construct(
        protected RoutineSchedule $schedule,
        protected AssistantService $assistant,
        protected ConversationService $conversations,
        protected ToolService $tools,
    ) {}

    /**
     * Get the user's routines with their most recent runs.
     *
     * @return Collection<int, Routine>
     */
    public function list(User $user): Collection
    {
        return $user->routines()
            ->with(['runs' => fn ($query) => $query->limit(self::RECENT_RUNS)])
            ->orderBy('name')
            ->get();
    }

    /**
     * Schedule a new routine for the user.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $user, array $attributes): Routine
    {
        $routine = $user->routines()->make();

        return $this->save($routine, $attributes);
    }

    /**
     * Update a routine and work out its next run again.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Routine $routine, array $attributes): Routine
    {
        return $this->save($routine, $attributes);
    }

    /**
     * Delete a routine and its run history. The conversations its runs created are kept.
     */
    public function delete(Routine $routine): void
    {
        $routine->delete();
    }

    /**
     * Describe the schedule in words.
     */
    public function describe(Routine $routine): string
    {
        return $this->schedule->describe($routine);
    }

    /**
     * Queue every routine that is due, and move each one's next run forward.
     *
     * The next run is moved with a conditional update, so a routine is only
     * queued once even if two schedulers check at the same moment.
     *
     * @return int How many routines were queued.
     */
    public function dispatchDue(): int
    {
        $queued = 0;

        Routine::query()->due()->each(function (Routine $routine) use (&$queued) {
            $claimed = Routine::query()
                ->whereKey($routine->id)
                ->where('next_run_at', $routine->next_run_at)
                ->update([
                    'next_run_at' => $this->schedule->nextRunAt($routine),
                    'last_run_at' => now(),
                ]);

            if ($claimed === 1) {
                RunRoutine::dispatch($routine);
                $queued++;
            }
        });

        return $queued;
    }

    /**
     * Queue a run of the routine now, without changing its schedule.
     */
    public function runNow(Routine $routine): void
    {
        RunRoutine::dispatch($routine);
    }

    /**
     * Run the routine: prompt the assistant in a new conversation with only the
     * routine's tools, approve its tool calls if the routine allows that, and
     * deliver the reply.
     *
     * @throws Throwable When the assistant fails; the run is marked failed first.
     */
    public function run(Routine $routine): RoutineRun
    {
        $user = $routine->user;

        $conversationId = $this->conversations->start(
            $user,
            $routine->name.' · '.now()->timezone($routine->timezone)->format('M j, g:i A'),
            $routine->model,
        );

        $run = $routine->runs()->create(['conversation_id' => $conversationId, 'status' => RoutineRunStatus::Running]);

        try {
            $tools = $this->tools->toolsFor($user, $routine->tools);

            $this->assistant->stream($user, $conversationId, $routine->instructions, $tools);

            $conversation = $this->conversations->find($user, $conversationId);

            for ($attempt = 0; $routine->auto_approve && $attempt < self::MAX_AUTO_APPROVALS; $attempt++) {
                $waiting = array_column($this->conversations->approvals($conversation), 'id');

                if ($waiting === []) {
                    break;
                }

                $this->assistant->stream($user, $conversationId, array_fill_keys($waiting, true), $tools);
            }
        } catch (Throwable $exception) {
            $run->update(['status' => RoutineRunStatus::Failed, 'error' => $exception->getMessage(), 'finished_at' => now()]);

            throw $exception;
        }

        $status = $this->conversations->approvals($conversation) === []
            ? RoutineRunStatus::Completed
            : RoutineRunStatus::WaitingForApproval;

        $run->update([
            'status' => $status,
            'error' => $this->deliver($routine, $conversation, $status),
            'finished_at' => now(),
        ]);

        return $run;
    }

    /**
     * Send the reply, or a note that the run is waiting for approval, to the
     * routine's chosen channels.
     *
     * @return string|null Why delivery failed, if it did.
     */
    protected function deliver(Routine $routine, Conversation $conversation, RoutineRunStatus $status): ?string
    {
        $url = route('chat.show', $conversation->id);

        $reply = $status === RoutineRunStatus::WaitingForApproval
            ? "{$routine->name} is waiting for you to approve what it wants to do."
            : (string) $conversation->messages()->where('role', 'assistant')->latest()->latest('id')->value('content');

        $errors = [];

        if ($routine->deliver_email) {
            try {
                Mail::to($routine->user->email)->send(new RoutineResult($routine->name, $reply, $url));
            } catch (Throwable $exception) {
                $errors[] = "Could not email the result: {$exception->getMessage()}";
            }
        }

        if ($routine->deliver_sms && $routine->sms_to !== null) {
            try {
                Notification::route(TwilioChannel::class, $routine->sms_to)
                    ->notify(new RoutineResultSms($routine->name, $this->plainText($reply), $url));
            } catch (Throwable $exception) {
                $errors[] = "Could not text the result: {$exception->getMessage()}";
            }
        }

        return $errors === [] ? null : implode(' ', $errors);
    }

    /**
     * The reply's Markdown as plain text, for a text message.
     */
    protected function plainText(string $markdown): string
    {
        $text = html_entity_decode(strip_tags(Str::markdown($markdown, ['html_input' => 'strip'])));

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /**
     * Fill the routine, derive its cron expression and work out its next run.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function save(Routine $routine, array $attributes): Routine
    {
        $routine->fill($attributes);

        $routine->cron = $this->schedule->cron(
            $routine->frequency,
            $routine->frequency === RoutineFrequency::Hourly ? null : $routine->time,
            $routine->day_of_week,
            $routine->every_hours,
            $attributes['cron'] ?? $routine->cron,
        );

        $routine->next_run_at = $this->schedule->nextRunAt($routine);
        $routine->save();

        return $routine;
    }
}
