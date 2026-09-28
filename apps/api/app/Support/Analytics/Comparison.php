<?php

namespace App\Support\Analytics;

use App\Support\Money;
use JsonSerializable;

/**
 * A metric in the current period next to the same metric in the previous period.
 *
 * `change` is the percent variation rounded to one decimal. It is 0.0 when both
 * values are zero and null when it is undefined (previous is zero, or either value is null).
 */
final class Comparison implements JsonSerializable
{
    private function __construct(
        public readonly int|string|null $value,
        public readonly int|string|null $previous,
        public readonly ?float $change,
    ) {}

    public static function ofCounts(int $current, int $previous): self
    {
        return new self($current, $previous, self::percentChange($current, $previous));
    }

    /**
     * Amounts in integer cents, serialized as decimal strings; null means undefined (e.g. no orders).
     */
    public static function ofMoney(?int $currentCents, ?int $previousCents): self
    {
        return new self(
            $currentCents === null ? null : Money::fromCents($currentCents),
            $previousCents === null ? null : Money::fromCents($previousCents),
            self::percentChange($currentCents, $previousCents),
        );
    }

    public static function percentChange(?int $current, ?int $previous): ?float
    {
        if ($current === null || $previous === null) {
            return null;
        }

        if ($previous === 0) {
            return $current === 0 ? 0.0 : null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    /**
     * @return array{value: int|string|null, previous: int|string|null, change: float|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'value' => $this->value,
            'previous' => $this->previous,
            'change' => $this->change,
        ];
    }
}
