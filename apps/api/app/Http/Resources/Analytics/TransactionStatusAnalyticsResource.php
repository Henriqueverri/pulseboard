<?php

namespace App\Http\Resources\Analytics;

use App\Support\Analytics\Comparison;
use Illuminate\Http\Request;

/**
 * @property list<array{status: string, orders: Comparison, revenue: Comparison, percentage: Comparison}> $resource
 */
class TransactionStatusAnalyticsResource extends AnalyticsResource
{
    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(Request $request): array
    {
        return array_map(fn (array $row): array => [
            'status' => $row['status'],
            'orders' => $row['orders']->jsonSerialize(),
            'revenue' => $row['revenue']->jsonSerialize(),
            'percentage' => $row['percentage']->jsonSerialize(),
        ], $this->resource);
    }
}
