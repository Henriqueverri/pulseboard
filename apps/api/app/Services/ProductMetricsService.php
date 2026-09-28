<?php

namespace App\Services;

use App\Enums\TransactionStatus;
use App\Models\Product;
use App\Models\TransactionItem;
use App\Support\Money;

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
            ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->where('transaction_items.product_id', $product->id)
            ->where('transactions.organization_id', $product->organization_id)
            ->where('transactions.status', TransactionStatus::Paid->value)
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
