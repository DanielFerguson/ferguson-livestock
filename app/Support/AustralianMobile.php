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
}
