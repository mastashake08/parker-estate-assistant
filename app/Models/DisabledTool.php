<?php

namespace App\Models;

use Database\Factories\DisabledToolFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A tool the user has switched off. Tools are enabled unless a row exists.
 *
 * @property int $id
 * @property int $user_id
 * @property string $tool
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['tool'])]
class DisabledTool extends Model
{
    /** @use HasFactory<DisabledToolFactory> */
    use HasFactory;

    /**
     * Get the user that disabled the tool.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
