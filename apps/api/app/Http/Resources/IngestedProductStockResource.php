<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The stock PulseBoard holds for a product, as seen by the integrating system.
 *
 * @mixin Product
 */
class IngestedProductStockResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'product_id' => $this->id,
            'sku' => $this->sku,
            'tracked' => $this->stock_tracked,
            'quantity' => $this->stock_quantity,
            'min_quantity' => $this->stock_min,
            'status' => $this->stockStatus()?->value,
            'updated_at' => $this->stock_updated_at?->toIso8601ZuluString('microsecond'),
        ];
    }
}
