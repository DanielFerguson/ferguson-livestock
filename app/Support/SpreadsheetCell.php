<?php

namespace App\Support;

/**
 * Spreadsheet apps run cells that start with =, +, - or @ as formulas, so text people typed is quoted first.
 */
final class SpreadsheetCell
{
    public static function safe(?string $value): ?string
    {
        return $value !== null && preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
