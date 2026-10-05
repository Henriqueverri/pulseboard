<?php

namespace App\Data\Ai;

use App\Models\AiInsight;

/**
 * A period's context with its summary, if there is one: `insight` is null when
 * nothing was generated yet for this exact data (GET without cache).
 */
final readonly class PeriodSummaryResult
{
    public function __construct(
        public PeriodSummaryContext $context,
        public ?AiInsight $insight,
        public bool $cached,
    ) {}
}
