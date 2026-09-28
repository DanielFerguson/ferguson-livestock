<?php

namespace App\Sms;

use Carbon\CarbonInterface;

/**
 * 8pm to 8am in Melbourne, when broadcasts aren't sent unless the admin insists.
 */
final class QuietHours
{
    public const int STARTS_AT_HOUR = 20;

    public const int ENDS_AT_HOUR = 8;

    public const string TIMEZONE = 'Australia/Melbourne';

    public static function contains(CarbonInterface $moment): bool
    {
        $hour = $moment->copy()->setTimezone(self::TIMEZONE)->hour;

        return $hour >= self::STARTS_AT_HOUR || $hour < self::ENDS_AT_HOUR;
    }
}
