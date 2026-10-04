<?php

namespace App\Enums;

/**
 * Lifecycle of a sale: pending -> paid -> refunded, or pending -> canceled.
 * No state is ever revisited, which the unique (transaction_id, to_status)
 * index on transaction_status_changes relies on.
 */
enum TransactionStatus: string
{
    case Paid = 'paid';
    case Refunded = 'refunded';
    case Pending = 'pending';
    case Canceled = 'canceled';

    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Pending => $to === self::Paid || $to === self::Canceled,
            self::Paid => $to === self::Refunded,
            self::Refunded, self::Canceled => false,
        };
    }

    public function isFinal(): bool
    {
        return $this === self::Refunded || $this === self::Canceled;
    }

    /**
     * A transaction is always created as pending or paid; refunds and cancellations are later transitions.
     */
    public function allowedOnCreate(): bool
    {
        return $this === self::Pending || $this === self::Paid;
    }

    /**
     * The statuses a transaction goes through, from creation, to end up in this one.
     *
     * @return non-empty-list<self>
     */
    public function pathFromCreation(): array
    {
        return match ($this) {
            self::Pending => [self::Pending],
            self::Paid => [self::Paid],
            self::Refunded => [self::Paid, self::Refunded],
            self::Canceled => [self::Pending, self::Canceled],
        };
    }
}
