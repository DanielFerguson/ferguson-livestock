<?php

namespace App\Stock;

/**
 * How much is left, in words. Keep in step with stockText() in resources/js/drop-status.js, which takes over
 * once the page is live.
 */
final class StockLabel
{
    /**
     * Below this many, show the number left.
     */
    public const int SHOW_COUNT_FROM = 10;

    public static function text(int $available): string
    {
        return match (true) {
            $available < 1 => 'Sold out',
            $available <= self::SHOW_COUNT_FROM => "{$available} left",
            default => 'Available',
        };
    }
}
