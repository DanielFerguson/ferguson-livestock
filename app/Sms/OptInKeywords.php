<?php

declare(strict_types=1);

namespace App\Sms;

/**
 * Whether a reply asks to start receiving texts again after opting out.
 *
 * Only Twilio's START and UNSTOP keywords count, and only as the whole reply, so a casual "yes" or a question that
 * mentions starting never re-subscribes anyone. Twilio unblocks the number for the same replies.
 */
final class OptInKeywords
{
    private const array KEYWORDS = ['start', 'unstop'];

    public static function matches(string $reply): bool
    {
        $words = trim((string) preg_replace('/[^a-z]+/', ' ', mb_strtolower($reply)));

        return in_array($words, self::KEYWORDS, true);
    }
}
