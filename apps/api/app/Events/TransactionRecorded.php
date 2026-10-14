<?php

namespace App\Events;

use App\Enums\TransactionStatus;
use App\Models\Transaction;

/** A new transaction was ingested (an idempotent replay is not a new transaction). */
final class TransactionRecorded extends OrganizationEvent
{
    public function __construct(
        string $organizationId,
        public readonly string $transactionId,
        public readonly TransactionStatus $status,
        public readonly string $totalAmount,
        public readonly string $currency,
        public readonly string $occurredAt,
    ) {
        parent::__construct($organizationId);
    }

    public static function of(Transaction $transaction, string $currency): self
    {
        return new self(
            organizationId: $transaction->organization_id,
            transactionId: $transaction->id,
            status: $transaction->status,
            totalAmount: (string) $transaction->total_amount,
            currency: $currency,
            occurredAt: $transaction->occurred_at->toISOString(),
        );
    }

    public function broadcastAs(): string
    {
        return 'transaction.recorded';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->transactionId,
            'status' => $this->status->value,
            'total_amount' => $this->totalAmount,
            'currency' => $this->currency,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
