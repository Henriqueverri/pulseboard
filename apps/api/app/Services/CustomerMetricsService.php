<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;

class CustomerMetricsService
{
    public const RECENT_TRANSACTIONS_LIMIT = 5;

    /**
     * orders_count and total_spent count only paid transactions;
     * recent_transactions lists the latest transactions of any status.
     *
     * @return array{orders_count: int, total_spent: string, recent_transactions: Collection<int, Transaction>}
     */
    public function for(Customer $customer): array
    {
        $totals = Transaction::query()
            ->forOrganization($customer->organization_id)
            ->where('customer_id', $customer->id)
            ->paid()
            ->toBase()
            ->selectRaw('count(*) as orders_count')
            ->selectRaw('coalesce(sum(total_amount), 0) as total_spent')
            ->first();

        $recent = Transaction::query()
            ->forOrganization($customer->organization_id)
            ->where('customer_id', $customer->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_TRANSACTIONS_LIMIT)
            ->get(['id', 'status', 'total_amount', 'occurred_at']);

        return [
            'orders_count' => (int) $totals->orders_count,
            'total_spent' => Money::fromCents(Money::toCents($totals->total_spent)),
            'recent_transactions' => $recent,
        ];
    }
}
