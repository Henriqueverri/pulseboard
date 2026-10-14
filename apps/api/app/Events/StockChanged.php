<?php

namespace App\Events;

use App\Enums\StockStatus;
use App\Models\Product;

/**
 * A product's mirrored stock was updated. `previousStatus` is the status before this update
 * (null when the product was not tracked), so listeners can tell when the minimum was crossed.
 */
final class StockChanged extends OrganizationEvent
{
    public function __construct(
        string $organizationId,
        public readonly string $productId,
        public readonly string $sku,
        public readonly string $name,
        public readonly int $quantity,
        public readonly int $minQuantity,
        public readonly ?StockStatus $status,
        public readonly ?StockStatus $previousStatus,
    ) {
        parent::__construct($organizationId);
    }

    public static function of(Product $product, ?StockStatus $previousStatus): self
    {
        return new self(
            organizationId: $product->organization_id,
            productId: $product->id,
            sku: $product->sku,
            name: $product->name,
            quantity: $product->stock_quantity,
            minQuantity: $product->stock_min,
            status: $product->stockStatus(),
            previousStatus: $previousStatus,
        );
    }

    public function broadcastAs(): string
    {
        return 'stock.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'product_id' => $this->productId,
            'sku' => $this->sku,
            'name' => $this->name,
            'tracked' => $this->status !== null,
            'quantity' => $this->quantity,
            'min_quantity' => $this->minQuantity,
            'status' => $this->status?->value,
            'previous_status' => $this->previousStatus?->value,
        ];
    }
}
