<?php

namespace App\Http\Resources;

use App\Models\TransactionItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * unit_price is the price at purchase time, not the product's current price.
 *
 * @mixin TransactionItem
 */
class TransactionItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'line_total' => $this->line_total,
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'sku' => $this->product->sku,
                'is_deleted' => $this->product->trashed(),
            ]),
        ];
    }
}
