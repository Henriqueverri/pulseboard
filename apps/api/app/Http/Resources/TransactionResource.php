<?php

namespace App\Http\Resources;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Relations are only rendered when eager loaded, so the same resource serves
 * compact embeds (e.g. a customer's recent transactions), listings and details.
 *
 * @mixin Transaction
 */
class TransactionResource extends JsonResource
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
            'items_count' => $this->whenCounted('items'),
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'email' => $this->customer->email,
                'is_deleted' => $this->customer->trashed(),
            ]),
            'items' => TransactionItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
