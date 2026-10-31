<?php

namespace App\Models;

use App\Enums\RoutineFrequency;
use Carbon\CarbonInterface;
use Database\Factories\RoutineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A prompt the assistant runs on a schedule, with the tools it may use and
 * where its result is delivered.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $instructions
 * @property RoutineFrequency $frequency
 * @property string|null $time
 * @property int|null $day_of_week
 * @property int|null $every_hours
 * @property string $cron
 * @property string $timezone
 * @property string|null $model
 * @property list<string> $tools
 * @property bool $auto_approve
 * @property bool $deliver_email
 * @property bool $deliver_sms
 * @property string|null $sms_to
 * @property bool $enabled
 * @property CarbonInterface|null $next_run_at
 * @property CarbonInterface|null $last_run_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name', 'instructions', 'frequency', 'time', 'day_of_week', 'every_hours', 'cron', 'timezone',
    'model', 'tools', 'auto_approve', 'deliver_email', 'deliver_sms', 'sms_to', 'enabled',
])]
class Routine extends Model
{
    /** @use HasFactory<RoutineFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'frequency' => RoutineFrequency::class,
            'day_of_week' => 'integer',
            'every_hours' => 'integer',
            'tools' => 'array',
            'auto_approve' => 'boolean',
            'deliver_email' => 'boolean',
            'deliver_sms' => 'boolean',
            'enabled' => 'boolean',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }

    /**
     * Get the user that owns the routine.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the times the routine has run, newest first.
     *
     * @return HasMany<RoutineRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(RoutineRun::class)->latest()->latest('id');
    }

    /**
     * Scope the query to enabled routines whose next run is due.
     *
     * @param  Builder<Routine>  $query
     */
    #[Scope]
    protected function due(Builder $query): void
    {
        $query->where('enabled', true)->where('next_run_at', '<=', now());
    }
}
