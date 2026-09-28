<?php

namespace Tests\Feature\Api\Analytics;

use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Analytics\ReportingPeriod;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Checks customer analytics against the dashboard, the other analytics endpoints,
 * customer details and independent row-level calculations over the demo dataset.
 *
 * The demo dataset has no soft-deleted customers and registers every customer before
 * its sales history starts, so registration and deletion rules are covered by
 * CustomerAnalyticsApiTest with controlled data instead.
 */
class CustomerAnalyticsConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private ReportingPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->organization = Organization::query()->sole();
        $this->owner = User::query()->where('email', 'test@example.com')->sole();
        $this->period = ReportingPeriod::lastDays(30, $this->organization->timezone);
    }

    public function test_summary_matches_the_dashboard_and_partitions_active_customers(): void
    {
        $query = $this->query($this->period);
        $dashboard = $this->fetch("/api/v1/dashboard?{$query}")->assertOk();
        $response = $this->fetch("/api/v1/analytics/customers?{$query}")->assertOk();

        $this->assertSame($dashboard->json('data.customers'), $response->json('summary.active_customers'));

        foreach (['value', 'previous'] as $key) {
            $this->assertSame(
                $response->json("summary.active_customers.{$key}"),
                $response->json("summary.new_customers.{$key}") + $response->json("summary.returning_customers.{$key}"),
                $key,
            );
        }

        $this->assertGreaterThan(0, $response->json('summary.returning_customers.value'));
        $this->assertLessThan($response->json('summary.total_customers.value'), $response->json('summary.active_customers.value'));
    }

    public function test_full_ranking_adds_up_to_the_dashboard_and_the_other_analytics(): void
    {
        $week = ReportingPeriod::lastDays(7, $this->organization->timezone);
        $query = $this->query($week);

        $dashboard = $this->fetch("/api/v1/dashboard?{$query}")->assertOk();
        $revenue = $this->fetch("/api/v1/analytics/revenue?{$query}")->assertOk();
        $products = $this->fetch("/api/v1/analytics/products?{$query}")->assertOk();

        foreach (['revenue', 'orders'] as $sort) {
            $response = $this->fetch("/api/v1/analytics/customers?{$query}&sort={$sort}&limit=50")->assertOk();
            $ranking = $response->json('data');

            $this->assertNotEmpty($ranking);
            $this->assertLessThan(50, count($ranking), 'the whole ranking must fit in one response');
            $this->assertSame(range(1, count($ranking)), array_column($ranking, 'rank'));

            $values = array_map(fn (array $row) => $sort === 'revenue' ? Money::toCents($row['revenue']['value']) : $row['orders']['value'], $ranking);
            $sorted = $values;
            rsort($sorted);
            $this->assertSame($sorted, $values, "ranking is ordered by {$sort}");

            $rankedRevenue = Money::fromCents(array_sum(array_map(fn (array $row) => Money::toCents($row['revenue']['value']), $ranking)));

            $this->assertSame($dashboard->json('data.revenue.value'), $rankedRevenue);
            $this->assertSame($revenue->json('summary.revenue.value'), $rankedRevenue);
            $this->assertSame($products->json('summary.revenue.value'), $rankedRevenue);
            $this->assertSame($dashboard->json('data.orders.value'), array_sum(array_map(fn (array $row) => $row['orders']['value'], $ranking)));
            $this->assertSame($response->json('summary.active_customers.value'), count($ranking));
        }
    }

    public function test_metrics_match_independent_calculations_over_paid_transactions(): void
    {
        $response = $this->fetch("/api/v1/analytics/customers?{$this->query($this->period)}&limit=50")->assertOk();

        $paid = Transaction::query()
            ->where('organization_id', $this->organization->id)
            ->where('status', 'paid')
            ->get(['customer_id', 'total_amount', 'occurred_at']);

        $firstPurchases = $paid->groupBy('customer_id')->map(fn (Collection $transactions) => $transactions->min('occurred_at'));

        foreach (['value' => $this->period, 'previous' => $this->period->previous()] as $key => $period) {
            $active = $this->within($paid, $period)->pluck('customer_id')->unique();
            $new = $active->filter(fn (string $id) => $firstPurchases[$id]->gte($period->startUtc()));

            $this->assertSame($active->count(), $response->json("summary.active_customers.{$key}"), "active {$key}");
            $this->assertSame($new->count(), $response->json("summary.new_customers.{$key}"), "new {$key}");
            $this->assertSame($active->count() - $new->count(), $response->json("summary.returning_customers.{$key}"), "returning {$key}");
        }

        $current = $this->within($paid, $this->period)->groupBy('customer_id');
        $previous = $this->within($paid, $this->period->previous())->groupBy('customer_id');

        foreach ($response->json('data') as $row) {
            $id = $row['customer']['id'];

            $this->assertSame($this->revenue($current[$id]), $row['revenue']['value'], $row['customer']['name']);
            $this->assertSame($current[$id]->count(), $row['orders']['value'], $row['customer']['name']);
            $this->assertSame($this->revenue($previous[$id] ?? collect()), $row['revenue']['previous'], $row['customer']['name']);
            $this->assertSame(($previous[$id] ?? collect())->count(), $row['orders']['previous'], $row['customer']['name']);
        }
    }

    public function test_previous_values_match_a_request_for_the_previous_period(): void
    {
        $current = $this->fetch("/api/v1/analytics/customers?{$this->query($this->period)}&limit=50")->assertOk();
        $earlier = $this->fetch("/api/v1/analytics/customers?{$this->query($this->period->previous())}&limit=50")->assertOk();

        foreach (['total_customers', 'active_customers', 'new_customers', 'returning_customers'] as $metric) {
            $this->assertSame($earlier->json("summary.{$metric}.value"), $current->json("summary.{$metric}.previous"), $metric);
        }

        $this->assertLessThan(50, count($earlier->json('data')), 'the whole previous ranking must fit in one response');

        $earlierById = collect($earlier->json('data'))->keyBy('customer.id');

        foreach ($current->json('data') as $row) {
            $match = $earlierById->get($row['customer']['id']);

            $this->assertSame($match['revenue']['value'] ?? '0.00', $row['revenue']['previous'], $row['customer']['name']);
            $this->assertSame($match['orders']['value'] ?? 0, $row['orders']['previous'], $row['customer']['name']);
        }
    }

    public function test_whole_history_matches_the_customer_detail_metrics(): void
    {
        $history = ReportingPeriod::lastDays(366, $this->organization->timezone);

        $ranking = $this->fetch("/api/v1/analytics/customers?{$this->query($history)}&limit=50")->assertOk()->json('data');

        $this->assertNotEmpty($ranking);

        foreach ($ranking as $row) {
            $this->fetch("/api/v1/customers/{$row['customer']['id']}")
                ->assertOk()
                ->assertJsonPath('data.total_spent', $row['revenue']['value'])
                ->assertJsonPath('data.orders_count', $row['orders']['value']);
        }
    }

    public function test_total_customers_ending_today_matches_the_customer_listing(): void
    {
        $total = $this->fetch('/api/v1/customers?per_page=1')->assertOk()->json('meta.total');

        $this->fetch("/api/v1/analytics/customers?{$this->query($this->period)}")
            ->assertOk()
            ->assertJsonPath('summary.total_customers.value', $total);
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return Collection<int, Transaction>
     */
    private function within(Collection $transactions, ReportingPeriod $period): Collection
    {
        return $transactions->filter(fn (Transaction $transaction) => $transaction->occurred_at->gte($period->startUtc())
            && $transaction->occurred_at->lt($period->endUtc()));
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     */
    private function revenue(Collection $transactions): string
    {
        return Money::fromCents($transactions->sum(fn (Transaction $transaction): int => Money::toCents($transaction->total_amount)));
    }

    private function query(ReportingPeriod $period): string
    {
        return "from={$period->from()}&to={$period->to()}";
    }

    private function fetch(string $uri): TestResponse
    {
        return $this->actingAs($this->owner)
            ->withHeader('X-Organization-Id', $this->organization->id)
            ->getJson($uri);
    }
}
