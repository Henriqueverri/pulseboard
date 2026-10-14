<?php

namespace App\Services\Ingestion;

use App\Data\Ingestion\ProductStockData;
use App\Data\Ingestion\StockUpdateResult;
use App\Exceptions\IngestionException;
use App\Models\Organization;
use App\Models\Product;

/**
 * Mirrors the stock owned by the integrating system. Every update carries the absolute
 * balance, so only the newest one matters: a single conditional UPDATE applies it when it
 * is newer than what is stored, and an older or repeated update changes nothing.
 */
final class ProductStockIngestionService
{
    /**
     * Microseconds are kept so two movements in the same second stay ordered.
     */
    private const TIMESTAMP_FORMAT = 'Y-m-d H:i:s.u';

    public function update(Organization $organization, string $sku, ProductStockData $data): StockUpdateResult
    {
        $occurredAt = $data->occurredAt->format(self::TIMESTAMP_FORMAT);

        $applied = Product::query()
            ->where('organization_id', $organization->id)
            ->where('sku', $sku)
            ->where(fn ($query) => $query
                ->whereNull('stock_updated_at')
                ->orWhere('stock_updated_at', '<', $occurredAt))
            ->update([
                'stock_tracked' => $data->tracked,
                'stock_quantity' => $data->quantity,
                'stock_min' => $data->minQuantity,
                'stock_updated_at' => $occurredAt,
            ]) > 0;

        $product = $organization->products()->where('sku', $sku)->first();

        if ($product === null) {
            throw IngestionException::validation(
                'The product could not be resolved.',
                'validation_failed',
                ['sku' => ['The selected product SKU is invalid.']],
            );
        }

        return new StockUpdateResult($product, $applied);
    }
}
