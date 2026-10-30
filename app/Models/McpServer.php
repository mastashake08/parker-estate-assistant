<?php

namespace App\Models;

use App\Enums\McpTransport;
use Database\Factories\McpServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property McpTransport $transport
 * @property string|null $command
 * @property array<int, string>|null $args
 * @property array<string, string>|null $env
 * @property string|null $url
 * @property array<string, string>|null $headers
 * @property int|null $timeout
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'transport', 'command', 'args', 'env', 'url', 'headers', 'timeout', 'enabled'])]
#[Hidden(['env', 'headers'])]
class McpServer extends Model
{
    /** @use HasFactory<McpServerFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transport' => McpTransport::class,
            'args' => 'array',
            'env' => 'encrypted:array',
            'headers' => 'encrypted:array',
            'timeout' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    /**
     * Get the user that owns the server.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope the query to servers the assistant may connect to.
     *
     * @param  Builder<McpServer>  $query
     */
    #[Scope]
    protected function enabled(Builder $query): void
    {
        $query->where('enabled', true);
    }

    /**
     * Get the tool preference key for one of this server's tools.
     */
    public function toolKey(string $toolName): string
    {
        return "mcp:{$this->id}:{$toolName}";
    }
}
