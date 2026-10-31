<?php

namespace App\Services;

use App\Enums\RoutineFrequency;
use App\Models\Routine;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use Illuminate\Support\Facades\Date;

/**
 * Turns a routine's schedule into a cron expression, its next run time and a description.
 */
class RoutineSchedule
{
    /**
     * Days of the week, indexed as cron numbers them (0 is Sunday).
     */
    public const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    /**
     * Build the cron expression for a schedule.
     *
     * @param  string|null  $time  The time of day as "HH:MM", for daily, weekday and weekly schedules.
     */
    public function cron(RoutineFrequency $frequency, ?string $time, ?int $dayOfWeek, ?int $everyHours, ?string $custom): string
    {
        [$hour, $minute] = array_map(intval(...), explode(':', $time ?? '0:0'));

        return match ($frequency) {
            RoutineFrequency::Daily => "{$minute} {$hour} * * *",
            RoutineFrequency::Weekdays => "{$minute} {$hour} * * 1-5",
            RoutineFrequency::Weekly => "{$minute} {$hour} * * {$dayOfWeek}",
            RoutineFrequency::Hourly => '0 */'.($everyHours ?? 1).' * * *',
            RoutineFrequency::Custom => trim((string) $custom),
        };
    }

    /**
     * When the routine next runs after the given moment, in UTC.
     *
     * The cron expression is read in the routine's timezone, so "8:00 AM"
     * stays 8:00 AM there across daylight saving changes.
     */
    public function nextRunAt(Routine $routine, ?CarbonInterface $after = null): CarbonInterface
    {
        $next = (new CronExpression($routine->cron))
            ->getNextRunDate(($after ?? now())->toDateTimeImmutable(), timeZone: $routine->timezone);

        return Date::instance($next)->utc();
    }

    /**
     * Describe the schedule in words, such as "Weekdays at 8:00 AM".
     */
    public function describe(Routine $routine): string
    {
        $time = $routine->time === null ? '' : Date::createFromFormat('H:i', $routine->time)?->format('g:i A');

        return match ($routine->frequency) {
            RoutineFrequency::Daily => "Every day at {$time}",
            RoutineFrequency::Weekdays => "Weekdays at {$time}",
            RoutineFrequency::Weekly => 'Every '.(self::DAYS[$routine->day_of_week ?? 0]).' at '.$time,
            RoutineFrequency::Hourly => $routine->every_hours === 1 ? 'Every hour' : "Every {$routine->every_hours} hours",
            RoutineFrequency::Custom => "Cron: {$routine->cron}",
        };
    }
}
