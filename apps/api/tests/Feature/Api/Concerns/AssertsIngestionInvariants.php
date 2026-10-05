<?php

namespace Tests\Feature\Api\Concerns;

use App\Enums\TransactionSource;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Domain\Concerns\AssertsStatusHistory;

/**
 * Invariants that must hold over the whole database after any mix of
 * ingestions and status changes, checked on the persisted rows rather than on
 * HTTP responses.
 */
trait AssertsIngestionInvariants
{
    use AssertsStatusHistory;

    protected function assertIngestionInvariants(): void
    {
        $this->assertSame(0, DB::table('transactions')
            ->join('api_keys', 'api_keys.id', '=', 'transactions.api_key_id')
            ->whereColumn('api_keys.organization_id', '<>', 'transactions.organization_id')
            ->count(), 'a transaction belongs to the organization of the key that created it');

        $this->assertSame(0, DB::table('transactions')
            ->join('customers', 'customers.id', '=', 'transactions.customer_id')
            ->whereColumn('customers.organization_id', '<>', 'transactions.organization_id')
            ->count(), 'a transaction customer belongs to the same organization');

        $this->assertSame(0, DB::table('transaction_items')
            ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->join('products', 'products.id', '=', 'transaction_items.product_id')
            ->whereColumn('products.organization_id', '<>', 'transactions.organization_id')
            ->count(), 'an item product belongs to the transaction organization');

        $this->assertSame([], DB::table('transactions')
            ->select('organization_id', 'external_id')
            ->whereNotNull('external_id')
            ->groupBy('organization_id', 'external_id')
            ->havingRaw('count(*) > 1')
            ->get()
            ->all(), 'an (organization_id, external_id) pair identifies a single transaction');

        $this->assertSame(0, DB::table('transactions')
            ->whereNotNull('api_key_id')
            ->where('source', '<>', TransactionSource::Ingest->value)
            ->count(), 'a transaction created by a key has source ingest');

        foreach (Transaction::query()->with('items')->get() as $transaction) {
            $this->assertNotEmpty($transaction->items, "{$transaction->id} has items");

            $lines = $transaction->items->map(function ($item) use ($transaction): int {
                $this->assertSame(
                    Money::toCents($item->unit_price) * $item->quantity,
                    Money::toCents($item->line_total),
                    "{$transaction->id} line total",
                );

                return Money::toCents($item->line_total);
            });

            $this->assertSame($lines->sum(), Money::toCents($transaction->total_amount), "{$transaction->id} total is the sum of its items");
        }

        // Single chain through the state machine, same organization and source, status = last to_status.
        $this->assertStatusHistoryMatchesCurrentStatus();
    }
}
