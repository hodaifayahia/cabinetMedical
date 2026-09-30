<?php

namespace App\Support;

/**
 * Integers as a French reader writes them, without depending on intl.
 */
final class FrenchNumber
{
    public static function format(int $value): string
    {
        return number_format($value, 0, ',', ' ');
    }

    /**
     * "845", "12,4 k", "3,1 M": token counts run into the millions.
     */
    public static function compact(int $value): string
    {
        $magnitude = abs($value);

        return match (true) {
            $magnitude >= 1_000_000 => rtrim(rtrim(number_format($value / 1_000_000, 1, ',', ' '), '0'), ',').' M',
            $magnitude >= 10_000 => rtrim(rtrim(number_format($value / 1_000, 1, ',', ' '), '0'), ',').' k',
            default => self::format($value),
        };
    }
}
