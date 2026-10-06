<?php

namespace App\Data\Ai;

use App\Support\Analytics\Comparison;

/**
 * One citable metric of an insight: the model only names it by `ref`; the
 * value always comes from the analytics service that produced `comparison`.
 */
final readonly class Evidence
{
    public const FORMAT_MONEY = 'money';

    public const FORMAT_COUNT = 'count';

    public const POLARITY_POSITIVE = 'positive';

    public const POLARITY_NEGATIVE = 'negative';

    public const POLARITY_NEUTRAL = 'neutral';

    /**
     * @param  string  $polarity  whether a rise is good (positive), bad (negative) or neither (neutral)
     * @param  string  $destination  the screen that shows this metric (PeriodSummarySchema::DESTINATIONS)
     */
    public function __construct(
        public string $ref,
        public string $label,
        public string $format,
        public Comparison $comparison,
        public string $polarity,
        public string $destination,
    ) {}

    /**
     * @return array{ref: string, label: string, format: string, polarity: string, destination: string, value: int|float|string|null, previous: int|float|string|null, change: float|null}
     */
    public function toArray(): array
    {
        return [
            'ref' => $this->ref,
            'label' => $this->label,
            'format' => $this->format,
            'polarity' => $this->polarity,
            'destination' => $this->destination,
            ...$this->comparison->jsonSerialize(),
        ];
    }
}
