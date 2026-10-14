<?php

namespace App\Data\Ingestion;

use App\Models\Product;

/**
 * applied is false when the update was not newer than the stock already stored:
 * a retry or an update that arrived out of order.
 */
final readonly class StockUpdateResult
{
    public function __construct(
        public Product $product,
        public bool $applied,
    ) {}
}
