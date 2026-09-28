<?php

namespace App\Services\Analytics;

use App\Http\Requests\Analytics\CustomerAnalyticsRequest;
use App\Models\Organization;
use App\Support\Analytics\Comparison;
use App\Support\Analytics\ReportingPeriod;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;

class CustomerAnalyticsService
{
    public function __construct(
        private readonly DashboardService $dashboard,
    ) {}

    /**
     * Customers ranked by their paid transactions in the period, each next to the previous
     * period, plus customer counts over the whole organization (not only the ranked ones).
     *
     * Revenue and orders use the dashboard definitions (paid total_amount, paid count). A
     * customer is ranked only if it bought in the current period; soft-deleted customers keep
     * their purchase history, so they are ranked like any other customer.
     *
     * @return array{
     *     ranking: list<array{
     *         rank: int,
     *         customer: array{id: string, name: string, email: string, is_deleted: bool},
     *         revenue: Comparison,
     *         orders: Comparison
     *     }>,
     *     summary: array{total_customers: Comparison, active_customers: Comparison, new_customers: Comparison, returning_customers: Comparison}
     * }
     */
    public function report(Organization $organization, ReportingPeriod $period, string $sort, int $limit): array
    {
        return [
            'ranking' => $this->ranking($organization, $period, $sort, $limit),
            'summary' => $this->summary($organization, $period),
        ];
    }

    /**
     * @return list<array{
     *     rank: int,
     *     customer: array{id: string, name: string, email: string, is_deleted: bool},
     *     revenue: Comparison,
     *     orders: Comparison
     * }>
     */
    private function ranking(Organization $organization, ReportingPeriod $period, string $sort, int $limit): array
    {
        $span = ReportingPeriod::fromDates($period->previous()->from(), $period->to(), $period->timezone);
        $split = $period->startUtc();

        $tiebreaker = $sort === CustomerAnalyticsRequest::SORT_ORDERS
            ? CustomerAnalyticsRequest::SORT_REVENUE
            : CustomerAnalyticsRequest::SORT_ORDERS;

        $rows = $organization->transactions()
            ->paid()
            ->occurredWithin($span)
            ->join('customers', fn (JoinClause $join) => $join
                ->on('customers.id', '=', 'transactions.customer_id')
                ->on('customers.organization_id', '=', 'transactions.organization_id'))
            ->toBase()
            ->select(['customers.id', 'customers.name', 'customers.email', 'customers.deleted_at'])
            ->selectRaw('coalesce(sum(case when transactions.occurred_at >= ? then transactions.total_amount end), 0) as revenue', [$split])
            ->selectRaw('coalesce(sum(case when transactions.occurred_at < ? then transactions.total_amount end), 0) as previous_revenue', [$split])
            ->selectRaw('count(case when transactions.occurred_at >= ? then 1 end) as orders', [$split])
            ->selectRaw('count(case when transactions.occurred_at < ? then 1 end) as previous_orders', [$split])
            ->groupBy('customers.id', 'customers.name', 'customers.email', 'customers.deleted_at')
            ->havingRaw('count(case when transactions.occurred_at >= ? then 1 end) > 0', [$split])
            ->orderByDesc($sort)
            ->orderByDesc($tiebreaker)
            ->orderBy('customers.name')
            ->orderBy('customers.id')
            ->limit($limit)
            ->get();

        return $rows->values()->map(fn (object $row, int $index): array => [
            'rank' => $index + 1,
            'customer' => [
                'id' => $row->id,
                'name' => $row->name,
                'email' => $row->email,
                'is_deleted' => $row->deleted_at !== null,
            ],
            'revenue' => Comparison::ofMoney(Money::toCents($row->revenue), Money::toCents($row->previous_revenue)),
            'orders' => Comparison::ofCounts((int) $row->orders, (int) $row->previous_orders),
        ])->all();
    }

    /**
     * active_customers comes from the dashboard so both endpoints share one definition;
     * new + returning always equals active.
     *
     * @return array{total_customers: Comparison, active_customers: Comparison, new_customers: Comparison, returning_customers: Comparison}
     */
    private function summary(Organization $organization, ReportingPeriod $period): array
    {
        $buyers = $this->buyers($organization, $period);
        $totals = $this->totals($organization, $period);

        return [
            'total_customers' => Comparison::ofCounts((int) $totals->total_customers, (int) $totals->previous_total_customers),
            'active_customers' => $this->dashboard->kpis($organization, $period)['customers'],
            'new_customers' => Comparison::ofCounts((int) $buyers->new_customers, (int) $buyers->previous_new_customers),
            'returning_customers' => Comparison::ofCounts((int) $buyers->returning_customers, (int) $buyers->previous_returning_customers),
        ];
    }

    /**
     * A customer is new in a period when its first paid transaction ever falls inside it,
     * and returning when it bought in the period after an earlier paid transaction.
     */
    private function buyers(Organization $organization, ReportingPeriod $period): object
    {
        $start = $period->startUtc();
        $previousStart = $period->previous()->startUtc();

        $firstPurchases = $organization->transactions()
            ->paid()
            ->where('transactions.occurred_at', '<', $period->endUtc())
            ->toBase()
            ->select('transactions.customer_id')
            ->selectRaw('min(transactions.occurred_at) as first_paid_at')
            ->selectRaw('max(case when transactions.occurred_at >= ? then 1 else 0 end) as active_current', [$start])
            ->selectRaw('max(case when transactions.occurred_at >= ? and transactions.occurred_at < ? then 1 else 0 end) as active_previous', [$previousStart, $start])
            ->groupBy('transactions.customer_id');

        return $firstPurchases->newQuery()
            ->fromSub($firstPurchases, 'buyers')
            ->selectRaw('count(case when active_current = 1 and first_paid_at >= ? then 1 end) as new_customers', [$start])
            ->selectRaw('count(case when active_current = 1 and first_paid_at < ? then 1 end) as returning_customers', [$start])
            ->selectRaw('count(case when active_previous = 1 and first_paid_at >= ? then 1 end) as previous_new_customers', [$previousStart])
            ->selectRaw('count(case when active_previous = 1 and first_paid_at < ? then 1 end) as previous_returning_customers', [$previousStart])
            ->first();
    }

    /**
     * Customer base at the end of each period: registered before it ends and not deleted by then.
     */
    private function totals(Organization $organization, ReportingPeriod $period): object
    {
        return $organization->customers()
            ->withTrashed()
            ->toBase()
            ->selectRaw(...$this->registeredAt($period->endUtc(), 'total_customers'))
            ->selectRaw(...$this->registeredAt($period->startUtc(), 'previous_total_customers'))
            ->first();
    }

    /**
     * @return array{0: string, 1: list<CarbonImmutable>}
     */
    private function registeredAt(CarbonImmutable $end, string $alias): array
    {
        return [
            "count(case when customers.created_at < ? and (customers.deleted_at is null or customers.deleted_at >= ?) then 1 end) as {$alias}",
            [$end, $end],
        ];
    }
}
