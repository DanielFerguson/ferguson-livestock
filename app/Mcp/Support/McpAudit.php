<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Models\McpToken;
use Illuminate\Support\Facades\Log;

/**
 * A record of what the AI client changed, in its own log channel so it's easy to read back.
 */
final class McpAudit
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function record(string $tool, array $arguments, string $outcome): void
    {
        $token = request()->attributes->get(McpToken::REQUEST_ATTRIBUTE);

        Log::channel('mcp')->info($tool, [
            'token_id' => $token instanceof McpToken ? $token->id : null,
            'outcome' => $outcome,
            'arguments' => self::redact($arguments),
        ]);
    }

    /**
     * Keep confirmation tokens out of the log, and cut long text such as message bodies.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private static function redact(array $arguments): array
    {
        $redacted = [];

        foreach ($arguments as $key => $value) {
            $redacted[$key] = match (true) {
                $key === 'confirmation_token' => '[redacted]',
                is_string($value) && mb_strlen($value) > 200 => mb_substr($value, 0, 200).'…',
                default => $value,
            };
        }

        return $redacted;
    }
}
