<?php

namespace App\Models;

use App\Enums\AudioGenerationStatus;
use App\Enums\AudioKind;
use Database\Factories\AudioGenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Speech, music, MIDI or sung vocals made in the audio studio, and the
 * file it produced.
 *
 * MIDI generations (and vocals, for their melody) also keep their stems: one entry per track, with its
 * name, General MIDI program, channel and notes (timed in beats), so the
 * browser can play each stem without reading the MIDI file.
 *
 * @property int $id
 * @property int $user_id
 * @property AudioKind $kind
 * @property AudioGenerationStatus $status
 * @property string $title
 * @property string $prompt
 * @property string|null $model
 * @property array<string, mixed> $options
 * @property string|null $path
 * @property string|null $score_path The DiffSinger score (.ds file) that vocals were sung from.
 * @property int|null $size
 * @property float|null $duration
 * @property list<array{name: string, program: int, channel: int, drums: bool, notes: list<array{pitch: int, start: float, duration: float, velocity: int}>}>|null $stems
 * @property string|null $error
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['kind', 'status', 'title', 'prompt', 'model', 'options', 'path', 'score_path', 'size', 'duration', 'stems', 'error', 'finished_at'])]
#[Hidden(['path', 'score_path'])]
class AudioGeneration extends Model
{
    /** @use HasFactory<AudioGenerationFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AudioKind::class,
            'status' => AudioGenerationStatus::class,
            'options' => 'array',
            'size' => 'integer',
            'duration' => 'float',
            'stems' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Get the user who made the generation.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get one of the generation's options.
     */
    public function option(string $key, mixed $default = null): mixed
    {
        return data_get($this->options, $key, $default);
    }
}
