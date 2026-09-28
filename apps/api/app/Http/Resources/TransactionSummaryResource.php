<?php

namespace App\Http\Resources;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact transaction representation embedded in other resources (no items or customer).
 *
 * @mixin Transaction
 */
class TransactionSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'total_amount' => $this->total_amount,
            'occurred_at' => $this->occurred_at,
        ];
    }
}
