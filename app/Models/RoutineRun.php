<?php

namespace App\Models;

use App\Enums\RoutineRunStatus;
use Database\Factories\RoutineRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $routine_id
 * @property string|null $conversation_id
 * @property RoutineRunStatus $status
 * @property string|null $error
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['conversation_id', 'status', 'error', 'finished_at'])]
class RoutineRun extends Model
{
    /** @use HasFactory<RoutineRunFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RoutineRunStatus::class,
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Get the routine that ran.
     *
     * @return BelongsTo<Routine, $this>
     */
    public function routine(): BelongsTo
    {
        return $this->belongsTo(Routine::class);
    }
}
