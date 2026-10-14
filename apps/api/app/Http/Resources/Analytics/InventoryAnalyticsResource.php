<?php

namespace App\Http\Resources\Analytics;

use App\Services\Analytics\InventoryAnalyticsService;
use App\Support\Analytics\ReportingPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Stock is a snapshot, not a period: meta describes only the sales window behind
 * the daily average.
 *
 * @see InventoryAnalyticsService::report()
 *
 * @property array{window: ReportingPeriod, summary: array<string, int>, items: list<array<string, mixed>>} $resource
 */
class InventoryAnalyticsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'summary' => $this->resource['summary'],
            'items' => $this->resource['items'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        $window = $this->resource['window'];

        return [
            'meta' => [
                'sales_window' => ['from' => $window->from(), 'to' => $window->to(), 'days' => $window->days()],
                'timezone' => $window->timezone,
            ],
        ];
    }

    /**
     * Keeps averages and days of cover floats in JSON (2.0, not 2).
     */
    public function jsonOptions(): int
    {
        return JSON_PRESERVE_ZERO_FRACTION;
    }
}
