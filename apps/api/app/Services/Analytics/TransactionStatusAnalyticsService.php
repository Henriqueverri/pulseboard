<?php

namespace App\Services\Analytics;

use App\Enums\TransactionStatus;
use App\Models\Organization;
use App\Support\Analytics\Comparison;
use App\Support\Analytics\ReportingPeriod;
use App\Support\Money;

class TransactionStatusAnalyticsService
{
    /**
     * Transactions of every status in the period, each status next to the previous period.
     *
     * This is the one analytics view that is not restricted to paid transactions: it
     * distributes all of them by their current status. `revenue` is the sum of total_amount
     * of the status, so only the paid row is revenue in the dashboard sense. `percentage` is
     * the status share of the period's orders, undefined (null) when the period has none.
     *
     * Both periods are contiguous in UTC, so a single scan over the combined span is split
     * at the start of the current period; statuses without transactions are filled with zeros.
     *
     * @return list<array{status: string, orders: Comparison, revenue: Comparison, percentage: Comparison}>
     */
    public function report(Organization $organization, ReportingPeriod $period): array
    {
        $span = ReportingPeriod::fromDates($period->previous()->from(), $period->to(), $period->timezone);
        $split = $period->startUtc();

        $rows = $organization->transactions()
            ->occurredWithin($span)
            ->toBase()
            ->select('transactions.status')
            ->selectRaw('count(case when transactions.occurred_at >= ? then 1 end) as orders', [$split])
            ->selectRaw('count(case when transactions.occurred_at < ? then 1 end) as previous_orders', [$split])
            ->selectRaw('coalesce(sum(case when transactions.occurred_at >= ? then transactions.total_amount end), 0) as revenue', [$split])
            ->selectRaw('coalesce(sum(case when transactions.occurred_at < ? then transactions.total_amount end), 0) as previous_revenue', [$split])
            ->groupBy('transactions.status')
            ->get()
            ->keyBy('status');

        $totalOrders = (int) $rows->sum('orders');
        $totalPreviousOrders = (int) $rows->sum('previous_orders');

        return array_map(function (TransactionStatus $status) use ($rows, $totalOrders, $totalPreviousOrders): array {
            $row = $rows->get($status->value);
            $orders = $row === null ? 0 : (int) $row->orders;
            $previousOrders = $row === null ? 0 : (int) $row->previous_orders;

            return [
                'status' => $status->value,
                'orders' => Comparison::ofCounts($orders, $previousOrders),
                'revenue' => Comparison::ofMoney(
                    $row === null ? 0 : Money::toCents($row->revenue),
                    $row === null ? 0 : Money::toCents($row->previous_revenue),
                ),
                'percentage' => Comparison::ofPercentages(
                    $this->shareInTenths($orders, $totalOrders),
                    $this->shareInTenths($previousOrders, $totalPreviousOrders),
                ),
            ];
        }, TransactionStatus::cases());
    }

    /**
     * Share in tenths of a percent, rounded half up; undefined (null) when there is nothing to share.
     */
    private function shareInTenths(int $orders, int $total): ?int
    {
        return $total === 0 ? null : (int) round($orders * 1000 / $total);
    }
}
