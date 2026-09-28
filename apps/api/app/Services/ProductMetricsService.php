<?php

namespace App\Services;

use App\Models\Product;
use App\Models\TransactionItem;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

class ProductMetricsService
{
    /**
     * Sales totals for a product, counting only paid transactions.
     *
     * @return array{units_sold: int, revenue: string}
     */
    public function for(Product $product): array
    {
        $totals = TransactionItem::query()
            ->where('transaction_items.product_id', $product->id)
            ->whereHas('transaction', fn (Builder $transaction) => $transaction
                ->forOrganization($product->organization_id)
                ->paid())
            ->toBase()
            ->selectRaw('coalesce(sum(transaction_items.quantity), 0) as units_sold')
            ->selectRaw('coalesce(sum(transaction_items.line_total), 0) as revenue')
            ->first();

        return [
            'units_sold' => (int) $totals->units_sold,
            'revenue' => Money::fromCents(Money::toCents($totals->revenue)),
        ];
    }
}
