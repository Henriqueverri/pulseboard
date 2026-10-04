<?php

namespace Tests\Feature\Domain\Concerns;

use App\Models\Transaction;
use App\Models\TransactionStatusChange;

trait AssertsStatusHistory
{
    /**
     * Every transaction's history must be one chain through the state machine:
     * null -> a creation status, each step an allowed transition, ending at transactions.status.
     * Several changes may share occurred_at, so the order comes from the chain, not from timestamps.
     */
    protected function assertStatusHistoryMatchesCurrentStatus(): void
    {
        $changesByTransaction = TransactionStatusChange::query()->get()->groupBy('transaction_id');
        $transactions = Transaction::query()->get();

        $this->assertNotEmpty($transactions);
        $this->assertSame(
            $transactions->count(),
            $changesByTransaction->count(),
            'Every transaction has a history and every history belongs to a transaction.',
        );

        foreach ($transactions as $transaction) {
            $history = $changesByTransaction->get($transaction->id, collect());
            $changes = $history->keyBy(fn (TransactionStatusChange $change) => $change->from_status->value ?? '');

            $current = null;
            $steps = 0;

            while ($steps <= $history->count() && ($change = $changes->get($current->value ?? '')) !== null) {
                $current === null
                    ? $this->assertTrue($change->to_status->allowedOnCreate(), "{$transaction->id} starts as {$change->to_status->value}")
                    : $this->assertTrue($current->canTransitionTo($change->to_status), "{$transaction->id}: {$current->value} -> {$change->to_status->value}");

                $this->assertSame($transaction->organization_id, $change->organization_id);
                $this->assertSame($transaction->source, $change->source);

                $current = $change->to_status;
                $steps++;
            }

            $this->assertSame($history->count(), $steps, "{$transaction->id} history is a single chain");
            $this->assertSame($transaction->status, $current, "{$transaction->id} status equals the last to_status");
        }
    }
}
