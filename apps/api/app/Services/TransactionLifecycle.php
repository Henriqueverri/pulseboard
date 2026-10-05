<?php

namespace App\Services;

use App\Data\Ingestion\StatusTransitionResult;
use App\Enums\TransactionStatus;
use App\Exceptions\IngestionException;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * The only place a transaction changes status after creation. TransactionStatus
 * stays the authority on which transitions exist; this service owns the
 * read-modify-write: it locks the row, applies the transition and records the
 * history in the same database transaction.
 */
final class TransactionLifecycle
{
    public function transition(
        Organization $organization,
        ?ApiKey $apiKey,
        string $externalId,
        TransactionStatus $to,
        CarbonInterface $occurredAt,
    ): StatusTransitionResult {
        $result = DB::transaction(function () use ($organization, $apiKey, $externalId, $to, $occurredAt): StatusTransitionResult {
            $transaction = $organization->transactions()
                ->where('external_id', $externalId)
                ->lockForUpdate()
                ->first();

            if ($transaction === null) {
                throw IngestionException::notFound('Transaction not found.');
            }

            $from = $transaction->status;

            if ($from === $to) {
                return new StatusTransitionResult($transaction, false);
            }

            $this->guardTransition($from, $to);
            $this->guardChronology($transaction, $occurredAt);

            $transaction->forceFill(['status' => $to])->save();

            $transaction->statusChanges()->create([
                'organization_id' => $organization->id,
                'from_status' => $from,
                'to_status' => $to,
                'occurred_at' => $occurredAt,
                'source' => $transaction->source,
                'api_key_id' => $apiKey?->id,
            ]);

            return new StatusTransitionResult($transaction, true);
        });

        return new StatusTransitionResult(
            $this->loadForResponse($result->transaction, $organization),
            $result->applied,
        );
    }

    private function guardTransition(TransactionStatus $from, TransactionStatus $to): void
    {
        if ($from->canTransitionTo($to)) {
            return;
        }

        $message = $from->isFinal()
            ? "The transaction is {$from->value} and can no longer change status."
            : "A transaction cannot go from {$from->value} to {$to->value}.";

        throw IngestionException::conflict($message, 'invalid_transition');
    }

    /**
     * The history is a chain, so a transition may not be reported as having
     * happened before the change it follows.
     */
    private function guardChronology(Transaction $transaction, CarbonInterface $occurredAt): void
    {
        $last = $transaction->statusChanges()->max('occurred_at');

        if ($last !== null && $occurredAt->lessThan($last)) {
            throw IngestionException::validation(
                'The status change is older than the previous one.',
                'validation_failed',
                ['occurred_at' => ['The occurred at field must not be before the previous status change.']],
            );
        }
    }

    private function loadForResponse(Transaction $transaction, Organization $organization): Transaction
    {
        $transaction->load([
            'customer',
            'items' => fn (HasMany $items) => $items->orderBy('created_at')->orderBy('id'),
            'items.product',
            'statusChanges',
        ]);
        $transaction->setRelation('organization', $organization);

        return $transaction;
    }
}
