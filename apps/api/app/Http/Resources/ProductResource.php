<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @var array{units_sold: int, revenue: string}|null
     */
    private ?array $metrics = null;

    /**
     * @param  array{units_sold: int, revenue: string}  $metrics
     */
    public function withMetrics(array $metrics): static
    {
        $this->metrics = $metrics;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            'price' => $this->price,
            'status' => $this->status->value,
            'external_id' => $this->external_id,
            $this->mergeWhen($this->metrics !== null, fn () => $this->metrics),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
