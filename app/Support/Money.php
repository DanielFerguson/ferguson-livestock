<?php

namespace App\Support;

use Illuminate\Support\Number;

/**
 * Formats AUD amounts stored in cents: whole dollars drop the cents ("$160"), anything else keeps them ("$12.50").
 */
final class Money
{
    public static function format(int $cents): string
    {
        return (string) Number::currency($cents / 100, in: 'AUD', locale: 'en_AU', precision: $cents % 100 === 0 ? 0 : 2);
    }

    public static function perKg(int $cents): string
    {
        return self::format($cents).'/kg';
    }
}
