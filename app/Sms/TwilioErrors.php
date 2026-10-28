<?php

declare(strict_types=1);

namespace App\Sms;

/**
 * Twilio's error codes that come up with Australian mobiles, in plain English.
 */
final class TwilioErrors
{
    private const array ERRORS = [
        21211 => 'Not a valid number',
        21610 => 'They replied STOP to Twilio',
        21614 => 'Not a mobile number',
        30003 => 'Phone off or out of range',
        30005 => 'Number no longer in use',
        30006 => 'Can’t receive texts',
        30007 => 'Blocked by their phone company as spam',
        30008 => 'Unknown problem at their phone company',
    ];

    public static function explain(?int $code): ?string
    {
        if ($code === null) {
            return null;
        }

        return self::ERRORS[$code] ?? "Twilio error {$code}";
    }
}
