<?php

namespace App\Enums;

enum StockStatus: string
{
    case Ok = 'ok';
    case Low = 'low';
    case Out = 'out';

    /**
     * Reaching the minimum already counts as low, like in the POS.
     */
    public static function of(int $quantity, int $min): self
    {
        return match (true) {
            $quantity <= 0 => self::Out,
            $quantity <= $min => self::Low,
            default => self::Ok,
        };
    }

    /**
     * Lower is more urgent.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Out => 0,
            self::Low => 1,
            self::Ok => 2,
        };
    }
}
