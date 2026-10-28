<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\McpTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A bearer token that lets an AI client call the MCP server as the admin.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $token_hash
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'name', 'token_hash', 'last_used_at', 'expires_at', 'revoked_at'])]
class McpToken extends Model
{
    /** @use HasFactory<McpTokenFactory> */
    use HasFactory;

    public const string PREFIX = 'flmcp_';

    /**
     * Where the middleware leaves the token on the request, for tools that record who made a change.
     */
    public const string REQUEST_ATTRIBUTE = 'mcp_token';

    /**
     * Create a token for the user. The plain text is only available here, so the caller must show it once.
     *
     * @return array{token: self, plainText: string}
     */
    public static function issue(User $user, string $name, ?CarbonImmutable $expiresAt = null): array
    {
        $plainText = self::PREFIX.Str::random(48);

        $token = self::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'token_hash' => self::hash($plainText),
            'expires_at' => $expiresAt,
        ]);

        return ['token' => $token, 'plainText' => $plainText];
    }

    public static function hash(string $plainText): string
    {
        return hash('sha256', $plainText);
    }

    /**
     * The token a request presented, if it is one we issued and is still good.
     */
    public static function findUsable(string $plainText): ?self
    {
        if (! str_starts_with($plainText, self::PREFIX)) {
            return null;
        }

        $token = self::query()->with('user')->firstWhere('token_hash', self::hash($plainText));

        return $token instanceof self && $token->isUsable() ? $token : null;
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
