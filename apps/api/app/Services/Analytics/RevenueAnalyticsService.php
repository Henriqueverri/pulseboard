<?php

namespace App\Services\Analytics;

use App\Models\Organization;
use App\Support\Analytics\Comparison;
use App\Support\Analytics\Granularity;
use App\Support\Analytics\LocalDateBucket;
use App\Support\Analytics\ReportingPeriod;
use App\Support\Money;

class RevenueAnalyticsService
{
    public function __construct(
        private readonly DashboardService $dashboard,
    ) {}

    /**
     * Paid revenue per local bucket, with every bucket of the period present (zero when there
     * were no sales), plus period totals compared with the previous period.
     *
     * The database aggregates at most one row per bucket; empty buckets are filled from
     * Granularity::buckets(), whose keys match LocalDateBucket's.
     *
     * @return array{
     *     series: list<array{bucket: string, from: string, to: string, revenue: string, orders: int}>,
     *     summary: array{revenue: Comparison, orders: Comparison}
     * }
     */
    public function report(Organization $organization, ReportingPeriod $period, Granularity $granularity): array
    {
        $query = $organization->transactions()->paid()->occurredWithin($period);

        [$bucket, $bindings] = LocalDateBucket::expression($query->getConnection(), 'transactions.occurred_at', $granularity, $period);

        $totals = $query->toBase()
            ->selectRaw("{$bucket} as bucket", $bindings)
            ->selectRaw('coalesce(sum(transactions.total_amount), 0) as revenue')
            ->selectRaw('count(*) as orders')
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $series = array_map(function (array $bucket) use ($totals): array {
            $row = $totals->get($bucket['period']);

            return [
                'bucket' => $bucket['period'],
                'from' => $bucket['from'],
                'to' => $bucket['to'],
                'revenue' => Money::fromCents($row === null ? 0 : Money::toCents($row->revenue)),
                'orders' => $row === null ? 0 : (int) $row->orders,
            ];
        }, $granularity->buckets($period));

        $kpis = $this->dashboard->kpis($organization, $period);

        return [
            'series' => $series,
            'summary' => ['revenue' => $kpis['revenue'], 'orders' => $kpis['orders']],
        ];
    }
}
