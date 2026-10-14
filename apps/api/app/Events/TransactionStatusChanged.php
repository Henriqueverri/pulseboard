<?php

namespace App\Events;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use Carbon\CarbonInterface;

/** A transaction moved to another status (e.g. paid → refunded); a repeated change is not. */
final class TransactionStatusChanged extends OrganizationEvent
{
    public function __construct(
        string $organizationId,
        public readonly string $transactionId,
        public readonly TransactionStatus $from,
        public readonly TransactionStatus $to,
        public readonly string $totalAmount,
        public readonly string $currency,
        public readonly string $occurredAt,
    ) {
        parent::__construct($organizationId);
    }

    public static function of(
        Transaction $transaction,
        TransactionStatus $from,
        string $currency,
        CarbonInterface $occurredAt,
    ): self {
        return new self(
            organizationId: $transaction->organization_id,
            transactionId: $transaction->id,
            from: $from,
            to: $transaction->status,
            totalAmount: (string) $transaction->total_amount,
            currency: $currency,
            occurredAt: $occurredAt->toISOString(),
        );
    }

    public function broadcastAs(): string
    {
        return 'transaction.status-changed';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->transactionId,
            'from' => $this->from->value,
            'to' => $this->to->value,
            'total_amount' => $this->totalAmount,
            'currency' => $this->currency,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
