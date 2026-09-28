<?php

namespace App\Support;

/**
 * Normalises however someone typed an Australian mobile number into E.164 (+614XXXXXXXX).
 */
final class AustralianMobile
{
    public static function toE164(string $number): ?string
    {
        $digits = preg_replace('/[\s\-().]/', '', $number) ?? '';

        $local = match (true) {
            str_starts_with($digits, '+61') => '0'.substr($digits, 3),
            str_starts_with($digits, '61') => '0'.substr($digits, 2),
            default => $digits,
        };

        if (preg_match('/^04\d{8}$/', $local) !== 1) {
            return null;
        }

        return '+61'.substr($local, 1);
    }

    /**
     * +61412345678 as 0412 345 678.
     */
    public static function format(string $e164): string
    {
        $local = '0'.substr($e164, 3);

        return substr($local, 0, 4).' '.substr($local, 4, 3).' '.substr($local, 7);
    }

    /**
     * The digits to look for in stored numbers when someone searches for a mobile as they'd write it, with or
     * without the leading 0 or +61. Null when the search has no digits.
     */
    public static function searchDigits(string $search): ?string
    {
        $digits = preg_replace('/\D/', '', $search) ?? '';

        if ($digits === '') {
            return null;
        }

        return match (true) {
            str_starts_with($digits, '61') => substr($digits, 2),
            str_starts_with($digits, '0') => substr($digits, 1),
            default => $digits,
        };
    }
}
