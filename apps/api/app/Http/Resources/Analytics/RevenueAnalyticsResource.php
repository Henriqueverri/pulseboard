<?php

namespace App\Http\Resources\Analytics;

use App\Support\Analytics\Comparison;
use App\Support\Analytics\Granularity;
use Illuminate\Http\Request;
use LogicException;

/**
 * @property array{
 *     series: list<array{bucket: string, from: string, to: string, revenue: string, orders: int}>,
 *     summary: array{revenue: Comparison, orders: Comparison}
 * } $resource
 */
class RevenueAnalyticsResource extends AnalyticsResource
{
    private ?Granularity $granularity = null;

    public function withGranularity(Granularity $granularity): static
    {
        $this->granularity = $granularity;

        return $this;
    }

    /**
     * @return list<array{bucket: string, from: string, to: string, revenue: string, orders: int}>
     */
    public function toArray(Request $request): array
    {
        return $this->resource['series'];
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'summary' => [
                'revenue' => $this->resource['summary']['revenue']->jsonSerialize(),
                'orders' => $this->resource['summary']['orders']->jsonSerialize(),
            ],
            ...parent::with($request),
        ];
    }

    /**
     * @return array{granularity: string}
     */
    protected function extraMeta(): array
    {
        if ($this->granularity === null) {
            throw new LogicException(static::class.' requires withGranularity() before serialization.');
        }

        return ['granularity' => $this->granularity->value];
    }
}
