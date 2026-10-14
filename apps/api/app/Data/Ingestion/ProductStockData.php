<?php

namespace App\Data\Ingestion;

use Carbon\CarbonImmutable;

/**
 * The absolute stock of a product at occurredAt, as the integrating system sees it.
 */
final readonly class ProductStockData
{
    public function __construct(
        public bool $tracked,
        public int $quantity,
        public int $minQuantity,
        public CarbonImmutable $occurredAt,
    ) {}
}
