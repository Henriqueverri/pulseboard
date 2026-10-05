<?php

namespace App\Data\Ingestion;

use App\Models\Transaction;

final readonly class IngestionResult
{
    public function __construct(
        public Transaction $transaction,
        public bool $replayed,
    ) {}
}
