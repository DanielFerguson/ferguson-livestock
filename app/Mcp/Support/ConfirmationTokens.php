<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;

/**
 * Ties an irreversible action to the exact preview the owner was shown.
 *
 * A "prepare" tool issues a single-use token for an action on one subject (say, sending broadcast 12). The token
 * only works if the fingerprint, a hash of what was previewed, still matches when the action tool consumes it, so
 * editing the broadcast after the preview voids the token.
 */
final readonly class ConfirmationTokens
{
    public const int TTL_MINUTES = 10;

    public function __construct(private Cache $cache) {}

    /**
     * @param  array<array-key, mixed>  $previewed  Everything the owner was shown that the action depends on.
     */
    public function issue(string $action, string $subject, array $previewed): string
    {
        $token = Str::random(24);

        $this->cache->put($this->key($token), [
            'action' => $action,
            'subject' => $subject,
            'fingerprint' => $this->fingerprint($previewed),
        ], now()->addMinutes(self::TTL_MINUTES));

        return $token;
    }

    /**
     * Use the token up. It works once, for the same action and subject, while the previewed state is unchanged.
     *
     * @param  array<array-key, mixed>  $previewed
     */
    public function consume(string $token, string $action, string $subject, array $previewed): bool
    {
        $stored = $this->cache->pull($this->key($token));

        if (! is_array($stored) || ! is_string($stored['fingerprint'] ?? null)) {
            return false;
        }

        return $stored['action'] === $action
            && $stored['subject'] === $subject
            && hash_equals($stored['fingerprint'], $this->fingerprint($previewed));
    }

    /**
     * @param  array<array-key, mixed>  $previewed
     */
    private function fingerprint(array $previewed): string
    {
        return hash('sha256', json_encode($previewed, JSON_THROW_ON_ERROR));
    }

    private function key(string $token): string
    {
        return 'mcp-confirmation:'.hash('sha256', $token);
    }
}
