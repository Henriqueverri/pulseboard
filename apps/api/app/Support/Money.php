<?php

namespace App\Support;

/**
 * Integer-cent arithmetic for decimal(12,2) money columns.
 */
final class Money
{
    public static function toCents(string|int|float $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    public static function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    public static function multiply(string|int|float $amount, int $quantity): string
    {
        return self::fromCents(self::toCents($amount) * $quantity);
    }
}
