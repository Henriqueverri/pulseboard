<?php

namespace App\Http\Resources\Analytics;

use App\Support\Analytics\Comparison;
use Illuminate\Http\Request;

/**
 * @property array{revenue: Comparison, orders: Comparison, average_order_value: Comparison, customers: Comparison} $resource
 */
class DashboardResource extends AnalyticsResource
{
    /**
     * @return array<string, array{value: int|string|null, previous: int|string|null, change: float|null}>
     */
    public function toArray(Request $request): array
    {
        return [
            'revenue' => $this->resource['revenue']->jsonSerialize(),
            'orders' => $this->resource['orders']->jsonSerialize(),
            'average_order_value' => $this->resource['average_order_value']->jsonSerialize(),
            'customers' => $this->resource['customers']->jsonSerialize(),
        ];
    }
}
