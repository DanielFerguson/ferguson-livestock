<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Models\SmsBroadcast;
use Carbon\CarbonImmutable;

/**
 * What the owner is shown when a broadcast is previewed. A confirmation token only works while this is unchanged,
 * so editing the message or audience, or the audience growing, voids it.
 */
final class BroadcastFingerprint
{
    /**
     * @return array<string, mixed>
     */
    public static function for(SmsBroadcast $broadcast, int $recipients, ?CarbonImmutable $sendAt, bool $acknowledgedQuietHours): array
    {
        return [
            'broadcast' => $broadcast->id,
            'message' => $broadcast->text()->text,
            'audience' => $broadcast->audience,
            'recipients' => $recipients,
            'send_at' => $sendAt?->toIso8601String(),
            'acknowledged_quiet_hours' => $acknowledgedQuietHours,
        ];
    }
}
