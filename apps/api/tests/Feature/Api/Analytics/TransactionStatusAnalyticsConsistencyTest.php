<?php

namespace Tests\Feature\Api\Analytics;

use App\Enums\TransactionStatus;
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
 * Checks the status distribution against the dashboard, revenue analytics, the transactions
 * listing and independent row-level calculations over the demo dataset.
 */
class TransactionStatusAnalyticsConsistencyTest extends TestCase
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

    public function test_paid_row_matches_the_dashboard_and_revenue_analytics(): void
    {
        $query = $this->query($this->period);
        $paid = $this->fetch("/api/v1/analytics/transactions?{$query}")->assertOk()->json('data.0');
        $dashboard = $this->fetch("/api/v1/dashboard?{$query}")->assertOk();
        $revenue = $this->fetch("/api/v1/analytics/revenue?{$query}")->assertOk();

        $this->assertSame('paid', $paid['status']);
        $this->assertSame($dashboard->json('data.orders'), $paid['orders']);
        $this->assertSame($dashboard->json('data.revenue'), $paid['revenue']);
        $this->assertSame($revenue->json('summary.revenue'), $paid['revenue']);
    }

    public function test_orders_of_all_statuses_match_the_transactions_listing_of_each_period(): void
    {
        $rows = $this->fetch("/api/v1/analytics/transactions?{$this->query($this->period)}")->assertOk()->json('data');

        foreach (['value' => $this->period, 'previous' => $this->period->previous()] as $key => $period) {
            $listed = $this->fetch("/api/v1/transactions?per_page=1&{$this->query($period)}")->assertOk()->json('meta.total');

            $this->assertSame($listed, array_sum(array_map(fn (array $row) => $row['orders'][$key], $rows)), $key);

            foreach (TransactionStatus::cases() as $index => $status) {
                $listedByStatus = $this->fetch("/api/v1/transactions?per_page=1&status={$status->value}&{$this->query($period)}")->json('meta.total');

                $this->assertSame($listedByStatus, $rows[$index]['orders'][$key], "{$status->value} {$key}");
            }
        }
    }

    public function test_every_status_matches_independent_calculations(): void
    {
        $rows = $this->fetch("/api/v1/analytics/transactions?{$this->query($this->period)}")->assertOk()->json('data');

        foreach (['value' => $this->period, 'previous' => $this->period->previous()] as $key => $period) {
            $transactions = $this->transactionsWithin($period);
            $total = $transactions->count();
            $percentages = [];

            $this->assertGreaterThan(0, $total, "{$key} period should have seeded transactions");
            $this->assertSame($this->revenue($transactions), Money::fromCents(array_sum(array_map(fn (array $row) => Money::toCents($row['revenue'][$key]), $rows))), "revenue {$key}");

            foreach (TransactionStatus::cases() as $index => $status) {
                $ofStatus = $transactions->where('status', $status);

                $this->assertSame($status->value, $rows[$index]['status']);
                $this->assertSame($ofStatus->count(), $rows[$index]['orders'][$key], "{$status->value} orders {$key}");
                $this->assertSame($this->revenue($ofStatus), $rows[$index]['revenue'][$key], "{$status->value} revenue {$key}");
                $this->assertSame(round($ofStatus->count() * 1000 / $total) / 10, $rows[$index]['percentage'][$key], "{$status->value} percentage {$key}");

                $percentages[] = $rows[$index]['percentage'][$key];
            }

            $this->assertEqualsWithDelta(100.0, array_sum($percentages), 0.2, "percentages {$key}");
        }
    }

    public function test_recent_period_has_every_status_and_paid_dominates(): void
    {
        $rows = collect($this->fetch("/api/v1/analytics/transactions?{$this->query($this->period)}")->assertOk()->json('data'));

        foreach ($rows as $row) {
            $this->assertGreaterThan(0, $row['orders']['value'], $row['status']);
        }

        $this->assertSame('paid', $rows->sortByDesc('percentage.value')->first()['status']);
        $this->assertGreaterThan(50.0, $rows->first()['percentage']['value']);
    }

    /**
     * @return Collection<int, Transaction>
     */
    private function transactionsWithin(ReportingPeriod $period): Collection
    {
        return Transaction::query()
            ->where('organization_id', $this->organization->id)
            ->where('occurred_at', '>=', $period->startUtc())
            ->where('occurred_at', '<', $period->endUtc())
            ->get(['status', 'total_amount']);
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
