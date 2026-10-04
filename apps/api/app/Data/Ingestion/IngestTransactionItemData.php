<?php

namespace App\Data\Ingestion;

use App\Support\Money;

final readonly class IngestTransactionItemData
{
    public function __construct(
        public string $sku,
        public int $quantity,
        public string $unitPrice,
    ) {}

    /**
     * @param  array{sku: string, quantity: int, unit_price: string}  $item
     */
    public static function fromArray(array $item): self
    {
        return new self($item['sku'], $item['quantity'], $item['unit_price']);
    }

    public function unitPriceCents(): int
    {
        return Money::parseToCents($this->unitPrice);
    }

    public function lineTotalCents(): int
    {
        return $this->unitPriceCents() * $this->quantity;
    }
}
