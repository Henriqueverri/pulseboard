<?php

namespace App\Http\Resources\Analytics;

use App\Support\Analytics\Comparison;
use Illuminate\Http\Request;
use LogicException;

/**
 * @property array{
 *     ranking: list<array{
 *         rank: int,
 *         product: array{id: string, name: string, sku: string|null, status: string, is_deleted: bool},
 *         revenue: Comparison,
 *         units_sold: Comparison
 *     }>,
 *     summary: array{revenue: Comparison, units_sold: Comparison, products_sold: Comparison}
 * } $resource
 */
class ProductAnalyticsResource extends AnalyticsResource
{
    private ?string $sort = null;

    private ?int $limit = null;

    public function withRanking(string $sort, int $limit): static
    {
        $this->sort = $sort;
        $this->limit = $limit;

        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(Request $request): array
    {
        return array_map(fn (array $row): array => [
            'rank' => $row['rank'],
            'product' => $row['product'],
            'revenue' => $row['revenue']->jsonSerialize(),
            'units_sold' => $row['units_sold']->jsonSerialize(),
        ], $this->resource['ranking']);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'summary' => [
                'revenue' => $this->resource['summary']['revenue']->jsonSerialize(),
                'units_sold' => $this->resource['summary']['units_sold']->jsonSerialize(),
                'products_sold' => $this->resource['summary']['products_sold']->jsonSerialize(),
            ],
            ...parent::with($request),
        ];
    }

    /**
     * @return array{sort: string, limit: int}
     */
    protected function extraMeta(): array
    {
        if ($this->sort === null || $this->limit === null) {
            throw new LogicException(static::class.' requires withRanking() before serialization.');
        }

        return ['sort' => $this->sort, 'limit' => $this->limit];
    }
}
