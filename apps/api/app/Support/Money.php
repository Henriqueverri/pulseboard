<?php

namespace App\Support;

/**
 * Integer-cent arithmetic for decimal(12,2) money columns.
 */
final class Money
{
    /**
     * Parses an already validated decimal string without passing through a float.
     */
    public static function parseToCents(string $amount): int
    {
        [$whole, $decimal] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($decimal, 2, '0');
    }

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
