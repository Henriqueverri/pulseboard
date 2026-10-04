<?php

namespace App\Data\Ingestion;

use App\Enums\TransactionStatus;
use Carbon\CarbonImmutable;

final readonly class IngestTransactionData
{
    /**
     * @param  list<IngestTransactionItemData>  $items
     */
    public function __construct(
        public string $externalId,
        public TransactionStatus $status,
        public CarbonImmutable $occurredAt,
        public string $currency,
        public string $customerExternalId,
        public string $customerName,
        public string $customerEmail,
        public array $items,
        public ?string $declaredTotal,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            externalId: $validated['external_id'],
            status: TransactionStatus::from($validated['status']),
            occurredAt: CarbonImmutable::parse($validated['occurred_at'])->utc(),
            currency: $validated['currency'],
            customerExternalId: $validated['customer']['external_id'],
            customerName: $validated['customer']['name'],
            customerEmail: $validated['customer']['email'],
            items: array_map(
                fn (array $item): IngestTransactionItemData => IngestTransactionItemData::fromArray($item),
                $validated['items'],
            ),
            declaredTotal: $validated['total_amount'] ?? null,
        );
    }

    public function totalCents(): int
    {
        return array_sum(array_map(
            fn (IngestTransactionItemData $item): int => $item->lineTotalCents(),
            $this->items,
        ));
    }
}
