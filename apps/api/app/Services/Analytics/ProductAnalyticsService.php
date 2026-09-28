<?php

namespace App\Services\Analytics;

use App\Http\Requests\Analytics\ProductAnalyticsRequest;
use App\Models\Organization;
use App\Support\Analytics\Comparison;
use App\Support\Analytics\ReportingPeriod;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;

class ProductAnalyticsService
{
    public function __construct(
        private readonly DashboardService $dashboard,
    ) {}

    /**
     * Products ranked by their paid sales in the period, each next to the previous period,
     * plus totals over every product sold (not only the ranked ones).
     *
     * Revenue per product is the sum of line_total (the price at sale time). A product is
     * ranked only if it sold in the current period. Soft-deleted and inactive products keep
     * their sales history, so they are ranked like any other product.
     *
     * @return array{
     *     ranking: list<array{
     *         rank: int,
     *         product: array{id: string, name: string, sku: string|null, status: string, is_deleted: bool},
     *         revenue: Comparison,
     *         units_sold: Comparison
     *     }>,
     *     summary: array{revenue: Comparison, units_sold: Comparison, products_sold: Comparison}
     * }
     */
    public function report(Organization $organization, ReportingPeriod $period, string $sort, int $limit): array
    {
        $span = ReportingPeriod::fromDates($period->previous()->from(), $period->to(), $period->timezone);
        $split = $period->startUtc();

        return [
            'ranking' => $this->ranking($organization, $span, $split, $sort, $limit),
            'summary' => $this->summary($organization, $period, $span, $split),
        ];
    }

    /**
     * @return list<array{
     *     rank: int,
     *     product: array{id: string, name: string, sku: string|null, status: string, is_deleted: bool},
     *     revenue: Comparison,
     *     units_sold: Comparison
     * }>
     */
    private function ranking(Organization $organization, ReportingPeriod $span, CarbonImmutable $split, string $sort, int $limit): array
    {
        $tiebreaker = $sort === ProductAnalyticsRequest::SORT_UNITS_SOLD
            ? ProductAnalyticsRequest::SORT_REVENUE
            : ProductAnalyticsRequest::SORT_UNITS_SOLD;

        $rows = $organization->transactions()
            ->paid()
            ->occurredWithin($span)
            ->join('transaction_items', 'transaction_items.transaction_id', '=', 'transactions.id')
            ->join('products', fn (JoinClause $join) => $join
                ->on('products.id', '=', 'transaction_items.product_id')
                ->on('products.organization_id', '=', 'transactions.organization_id'))
            ->toBase()
            ->select(['products.id', 'products.name', 'products.sku', 'products.status', 'products.deleted_at'])
            ->selectRaw('coalesce(sum(case when transactions.occurred_at >= ? then transaction_items.line_total end), 0) as revenue', [$split])
            ->selectRaw('coalesce(sum(case when transactions.occurred_at < ? then transaction_items.line_total end), 0) as previous_revenue', [$split])
            ->selectRaw('coalesce(sum(case when transactions.occurred_at >= ? then transaction_items.quantity end), 0) as units_sold', [$split])
            ->selectRaw('coalesce(sum(case when transactions.occurred_at < ? then transaction_items.quantity end), 0) as previous_units_sold', [$split])
            ->groupBy('products.id', 'products.name', 'products.sku', 'products.status', 'products.deleted_at')
            ->havingRaw('count(case when transactions.occurred_at >= ? then 1 end) > 0', [$split])
            ->orderByDesc($sort)
            ->orderByDesc($tiebreaker)
            ->orderBy('products.name')
            ->orderBy('products.id')
            ->limit($limit)
            ->get();

        return $rows->values()->map(fn (object $row, int $index): array => [
            'rank' => $index + 1,
            'product' => [
                'id' => $row->id,
                'name' => $row->name,
                'sku' => $row->sku,
                'status' => $row->status,
                'is_deleted' => $row->deleted_at !== null,
            ],
            'revenue' => Comparison::ofMoney(Money::toCents($row->revenue), Money::toCents($row->previous_revenue)),
            'units_sold' => Comparison::ofCounts((int) $row->units_sold, (int) $row->previous_units_sold),
        ])->all();
    }

    /**
     * Revenue comes from the dashboard so every endpoint shares one definition;
     * units and distinct products are aggregated over all paid items.
     *
     * @return array{revenue: Comparison, units_sold: Comparison, products_sold: Comparison}
     */
    private function summary(Organization $organization, ReportingPeriod $period, ReportingPeriod $span, CarbonImmutable $split): array
    {
        $totals = $organization->transactions()
            ->paid()
            ->occurredWithin($span)
            ->join('transaction_items', 'transaction_items.transaction_id', '=', 'transactions.id')
            ->toBase()
            ->selectRaw('coalesce(sum(case when transactions.occurred_at >= ? then transaction_items.quantity end), 0) as units_sold', [$split])
            ->selectRaw('coalesce(sum(case when transactions.occurred_at < ? then transaction_items.quantity end), 0) as previous_units_sold', [$split])
            ->selectRaw('count(distinct case when transactions.occurred_at >= ? then transaction_items.product_id end) as products_sold', [$split])
            ->selectRaw('count(distinct case when transactions.occurred_at < ? then transaction_items.product_id end) as previous_products_sold', [$split])
            ->first();

        return [
            'revenue' => $this->dashboard->kpis($organization, $period)['revenue'],
            'units_sold' => Comparison::ofCounts((int) $totals->units_sold, (int) $totals->previous_units_sold),
            'products_sold' => Comparison::ofCounts((int) $totals->products_sold, (int) $totals->previous_products_sold),
        ];
    }
}
