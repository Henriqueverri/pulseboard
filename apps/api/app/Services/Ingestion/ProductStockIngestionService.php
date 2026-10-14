<?php

namespace App\Services\Ingestion;

use App\Data\Ingestion\ProductStockData;
use App\Data\Ingestion\StockUpdateResult;
use App\Events\DomainEvents;
use App\Events\StockChanged;
use App\Exceptions\IngestionException;
use App\Models\Organization;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors the stock owned by the integrating system. Every update carries the absolute
 * balance, so only the newest one matters: a conditional UPDATE applies it when it is newer
 * than what is stored, and an older or repeated update changes nothing. The product row is
 * locked first, so the status before the update is known (crossing the minimum is a fact
 * listeners care about) and concurrent updates of one product apply one at a time.
 */
final class ProductStockIngestionService
{
    /**
     * Microseconds are kept so two movements in the same second stay ordered.
     */
    private const TIMESTAMP_FORMAT = 'Y-m-d H:i:s.u';

    public function __construct(private readonly DomainEvents $events) {}

    public function update(Organization $organization, string $sku, ProductStockData $data): StockUpdateResult
    {
        $occurredAt = $data->occurredAt->format(self::TIMESTAMP_FORMAT);

        [$product, $applied, $previousStatus] = DB::transaction(function () use ($organization, $sku, $data, $occurredAt): array {
            $current = $organization->products()->where('sku', $sku)->lockForUpdate()->first();

            if ($current === null) {
                throw IngestionException::validation(
                    'The product could not be resolved.',
                    'validation_failed',
                    ['sku' => ['The selected product SKU is invalid.']],
                );
            }

            $applied = Product::query()
                ->whereKey($current->id)
                ->where(fn ($query) => $query
                    ->whereNull('stock_updated_at')
                    ->orWhere('stock_updated_at', '<', $occurredAt))
                ->update([
                    'stock_tracked' => $data->tracked,
                    'stock_quantity' => $data->quantity,
                    'stock_min' => $data->minQuantity,
                    'stock_updated_at' => $occurredAt,
                ]) > 0;

            return [$applied ? $current->fresh() : $current, $applied, $current->stockStatus()];
        });

        if ($applied) {
            $this->events->dispatch(StockChanged::of($product, $previousStatus));
        }

        return new StockUpdateResult($product, $applied);
    }
}
