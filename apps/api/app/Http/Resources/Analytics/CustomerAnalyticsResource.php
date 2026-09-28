<?php

namespace App\Http\Resources\Analytics;

use App\Support\Analytics\Comparison;
use Illuminate\Http\Request;
use LogicException;

/**
 * @property array{
 *     ranking: list<array{
 *         rank: int,
 *         customer: array{id: string, name: string, email: string, is_deleted: bool},
 *         revenue: Comparison,
 *         orders: Comparison
 *     }>,
 *     summary: array{total_customers: Comparison, active_customers: Comparison, new_customers: Comparison, returning_customers: Comparison}
 * } $resource
 */
class CustomerAnalyticsResource extends AnalyticsResource
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
            'customer' => $row['customer'],
            'revenue' => $row['revenue']->jsonSerialize(),
            'orders' => $row['orders']->jsonSerialize(),
        ], $this->resource['ranking']);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'summary' => array_map(
                fn (Comparison $metric): array => $metric->jsonSerialize(),
                $this->resource['summary'],
            ),
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
