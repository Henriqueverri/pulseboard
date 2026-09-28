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
        public readonly int|float|string|null $value,
        public readonly int|float|string|null $previous,
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

    /**
     * Shares in integer tenths of a percent (653 = 65.3%), serialized as one-decimal floats;
     * null means undefined (e.g. nothing to share). `change` is the relative variation of the
     * shares as displayed, not the difference in percentage points.
     */
    public static function ofPercentages(?int $currentTenths, ?int $previousTenths): self
    {
        return new self(
            $currentTenths === null ? null : $currentTenths / 10.0,
            $previousTenths === null ? null : $previousTenths / 10.0,
            self::percentChange($currentTenths, $previousTenths),
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
     * @return array{value: int|float|string|null, previous: int|float|string|null, change: float|null}
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
