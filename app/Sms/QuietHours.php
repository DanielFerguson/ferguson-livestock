<?php

declare(strict_types=1);

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

    /**
     * The moment itself if it's outside quiet hours, otherwise the 8am that ends them.
     */
    public static function nextSendingTime(CarbonInterface $moment): CarbonInterface
    {
        if (! self::contains($moment)) {
            return $moment;
        }

        $melbourne = $moment->copy()->setTimezone(self::TIMEZONE);
        $morning = $melbourne->copy()->setTime(self::ENDS_AT_HOUR, 0);

        return $morning->isBefore($melbourne) ? $morning->addDay() : $morning;
    }
}
