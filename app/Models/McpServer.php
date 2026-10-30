<?php

namespace App\Models;

use App\Enums\McpTransport;
use Database\Factories\McpServerFactory;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
 * @property string|null $oauth_client_id
 * @property string|null $oauth_client_secret
 * @property string|null $oauth_scope
 * @property array{access_token: string, refresh_token: string|null, expires_at: int|null, client_id: string|null, client_secret: string|null}|null $oauth_tokens
 * @property-read bool $signed_in
 * @property int|null $timeout
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'transport', 'command', 'args', 'env', 'url', 'headers', 'oauth_client_id', 'oauth_client_secret', 'oauth_scope', 'timeout', 'enabled'])]
#[Hidden(['env', 'headers', 'oauth_client_secret', 'oauth_tokens'])]
#[Appends(['signed_in'])]
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
            'oauth_client_secret' => 'encrypted',
            'oauth_tokens' => 'encrypted:array',
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
     * Whether the user has signed in to the server with OAuth.
     *
     * @return Attribute<bool, never>
     */
    protected function signedIn(): Attribute
    {
        return Attribute::get(fn (): bool => $this->oauth_tokens !== null);
    }

    /**
     * Get the tool preference key for one of this server's tools.
     */
    public function toolKey(string $toolName): string
    {
        return "mcp:{$this->id}:{$toolName}";
    }
}
