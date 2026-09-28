<?php

namespace App\Services\Analytics;

use App\Models\Organization;
use App\Support\Analytics\Comparison;
use App\Support\Analytics\ReportingPeriod;
use App\Support\Money;

class DashboardService
{
    /**
     * Headline KPIs of paid transactions in the period, each next to the previous period.
     *
     * Both periods are contiguous in UTC, so a single scan over the combined span
     * is split at the start of the current period.
     *
     * @return array{revenue: Comparison, orders: Comparison, average_order_value: Comparison, customers: Comparison}
     */
    public function kpis(Organization $organization, ReportingPeriod $period): array
    {
        $span = ReportingPeriod::fromDates($period->previous()->from(), $period->to(), $period->timezone);
        $split = $period->startUtc();

        $totals = $organization->transactions()
            ->paid()
            ->occurredWithin($span)
            ->toBase()
            ->selectRaw('coalesce(sum(case when transactions.occurred_at >= ? then transactions.total_amount end), 0) as revenue', [$split])
            ->selectRaw('coalesce(sum(case when transactions.occurred_at < ? then transactions.total_amount end), 0) as previous_revenue', [$split])
            ->selectRaw('count(case when transactions.occurred_at >= ? then 1 end) as orders', [$split])
            ->selectRaw('count(case when transactions.occurred_at < ? then 1 end) as previous_orders', [$split])
            ->selectRaw('count(distinct case when transactions.occurred_at >= ? then transactions.customer_id end) as customers', [$split])
            ->selectRaw('count(distinct case when transactions.occurred_at < ? then transactions.customer_id end) as previous_customers', [$split])
            ->first();

        $revenue = Money::toCents($totals->revenue);
        $previousRevenue = Money::toCents($totals->previous_revenue);
        $orders = (int) $totals->orders;
        $previousOrders = (int) $totals->previous_orders;

        return [
            'revenue' => Comparison::ofMoney($revenue, $previousRevenue),
            'orders' => Comparison::ofCounts($orders, $previousOrders),
            'average_order_value' => Comparison::ofMoney(
                $this->averageOrderValue($revenue, $orders),
                $this->averageOrderValue($previousRevenue, $previousOrders),
            ),
            'customers' => Comparison::ofCounts((int) $totals->customers, (int) $totals->previous_customers),
        ];
    }

    /**
     * In cents, rounded half up; undefined (null) without orders.
     */
    private function averageOrderValue(int $revenueCents, int $orders): ?int
    {
        return $orders === 0 ? null : (int) round($revenueCents / $orders);
    }
}
