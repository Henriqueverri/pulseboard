<?php

namespace App\Services\Ingestion;

use App\Data\Ingestion\IngestTransactionData;
use App\Data\Ingestion\IngestTransactionItemData;

final class TransactionFingerprint
{
    public function make(IngestTransactionData $data): string
    {
        $items = array_map(
            fn (IngestTransactionItemData $item): array => [
                'sku' => $item->sku,
                'quantity' => $item->quantity,
                'unit_price_cents' => $item->unitPriceCents(),
            ],
            $data->items,
        );

        usort($items, fn (array $left, array $right): int => $left['sku'] <=> $right['sku']);

        $canonical = [
            'external_id' => $data->externalId,
            'occurred_at' => $data->occurredAt->format('Y-m-d\TH:i:s.u\Z'),
            'currency' => $data->currency,
            'status' => $data->status->value,
            'customer_external_id' => $data->customerExternalId,
            'items' => $items,
        ];

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
