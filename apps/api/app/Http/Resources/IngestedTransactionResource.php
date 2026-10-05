<?php

namespace App\Http\Resources;

use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A transaction as seen by an integrating system: identified by its own
 * external ids, without customer personal data. Expects customer, items.product,
 * statusChanges and organization to be loaded.
 *
 * @mixin Transaction
 */
class IngestedTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'external_id' => $this->external_id,
            'status' => $this->status->value,
            'currency' => $this->organization->currency,
            'total_amount' => $this->total_amount,
            'occurred_at' => $this->occurred_at,
            'customer' => [
                'id' => $this->customer->id,
                'external_id' => $this->customer->external_id,
            ],
            'items' => $this->items->map(fn (TransactionItem $item): array => [
                'product_id' => $item->product_id,
                'sku' => $item->product->sku,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'line_total' => $item->line_total,
            ])->all(),
            'status_history' => TransactionStatusChangeResource::collection($this->resource->statusHistory()),
        ];
    }
}
