<?php

namespace App\Data\Ingestion;

use App\Enums\TransactionStatus;
use App\Models\Transaction;

/**
 * applied is false when the transaction was already in the requested status,
 * which is a retry rather than a new transition. from is the status before it.
 */
final readonly class StatusTransitionResult
{
    public function __construct(
        public Transaction $transaction,
        public bool $applied,
        public TransactionStatus $from,
    ) {}
}
