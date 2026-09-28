<?php

namespace Tests\Feature\Api\Analytics;

use App\Models\Organization;
use App\Models\TransactionItem;
use App\Models\User;
use App\Support\Analytics\ReportingPeriod;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Checks the product ranking against the dashboard, revenue analytics, product metrics
 * and independent row-level calculations over the demo dataset.
 */
class ProductAnalyticsConsistencyTest extends TestCase
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

    public function test_full_ranking_adds_up_to_the_summary_the_dashboard_and_revenue_analytics(): void
    {
        $query = "from={$this->period->from()}&to={$this->period->to()}";
        $dashboard = $this->fetch("/api/v1/dashboard?{$query}")->assertOk();
        $revenue = $this->fetch("/api/v1/analytics/revenue?{$query}")->assertOk();

        foreach (['revenue', 'units_sold'] as $sort) {
            $response = $this->fetch("/api/v1/analytics/products?{$query}&sort={$sort}&limit=50")->assertOk();
            $ranking = $response->json('data');

            $this->assertNotEmpty($ranking);
            $this->assertLessThan(50, count($ranking), 'the whole ranking must fit in one response');
            $this->assertSame(range(1, count($ranking)), array_column($ranking, 'rank'));

            $values = array_map(fn (array $row) => $sort === 'revenue' ? Money::toCents($row['revenue']['value']) : $row['units_sold']['value'], $ranking);
            $sorted = $values;
            rsort($sorted);
            $this->assertSame($sorted, $values, "ranking is ordered by {$sort}");

            $rankedRevenue = Money::fromCents(array_sum(array_map(fn (array $row) => Money::toCents($row['revenue']['value']), $ranking)));

            $this->assertSame($dashboard->json('data.revenue'), $response->json('summary.revenue'));
            $this->assertSame($revenue->json('summary.revenue'), $response->json('summary.revenue'));
            $this->assertSame($response->json('summary.revenue.value'), $rankedRevenue);
            $this->assertSame($response->json('summary.units_sold.value'), array_sum(array_map(fn (array $row) => $row['units_sold']['value'], $ranking)));
            $this->assertSame($response->json('summary.products_sold.value'), count($ranking));
        }
    }

    public function test_previous_values_match_a_request_for_the_previous_period(): void
    {
        $previous = $this->period->previous();

        $current = $this->fetch("/api/v1/analytics/products?from={$this->period->from()}&to={$this->period->to()}&limit=50")->assertOk();
        $earlier = $this->fetch("/api/v1/analytics/products?from={$previous->from()}&to={$previous->to()}&limit=50")->assertOk();

        foreach (['revenue', 'units_sold', 'products_sold'] as $metric) {
            $this->assertSame($earlier->json("summary.{$metric}.value"), $current->json("summary.{$metric}.previous"), $metric);
        }

        $earlierById = collect($earlier->json('data'))->keyBy('product.id');

        foreach ($current->json('data') as $row) {
            $match = $earlierById->get($row['product']['id']);

            $this->assertSame($match['revenue']['value'] ?? '0.00', $row['revenue']['previous'], $row['product']['name']);
            $this->assertSame($match['units_sold']['value'] ?? 0, $row['units_sold']['previous'], $row['product']['name']);
        }
    }

    public function test_each_product_matches_its_paid_items_in_both_periods(): void
    {
        $response = $this->fetch("/api/v1/analytics/products?from={$this->period->from()}&to={$this->period->to()}&limit=50")->assertOk();

        $current = $this->expectedByProduct($this->period);
        $previous = $this->expectedByProduct($this->period->previous());

        $this->assertEqualsCanonicalizing(array_keys($current), $response->json('data.*.product.id'));

        foreach ($response->json('data') as $row) {
            $id = $row['product']['id'];

            $this->assertSame($current[$id]['revenue'], $row['revenue']['value'], $row['product']['name']);
            $this->assertSame($current[$id]['units_sold'], $row['units_sold']['value'], $row['product']['name']);
            $this->assertSame($previous[$id]['revenue'] ?? '0.00', $row['revenue']['previous'], $row['product']['name']);
            $this->assertSame($previous[$id]['units_sold'] ?? 0, $row['units_sold']['previous'], $row['product']['name']);
        }
    }

    public function test_whole_history_matches_the_product_detail_metrics(): void
    {
        $history = ReportingPeriod::lastDays(366, $this->organization->timezone);

        $ranking = $this->fetch("/api/v1/analytics/products?from={$history->from()}&to={$history->to()}&limit=50")
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($ranking);

        foreach ($ranking as $row) {
            $this->fetch("/api/v1/products/{$row['product']['id']}")
                ->assertOk()
                ->assertJsonPath('data.revenue', $row['revenue']['value'])
                ->assertJsonPath('data.units_sold', $row['units_sold']['value']);
        }
    }

    public function test_deactivated_products_only_rank_in_older_periods(): void
    {
        $previous = $this->period->previous();

        $recent = $this->fetch("/api/v1/analytics/products?from={$this->period->from()}&to={$this->period->to()}&limit=50")->assertOk();
        $older = $this->fetch("/api/v1/analytics/products?from={$previous->from()}&to={$previous->to()}&limit=50")->assertOk();

        $this->assertNotContains('inactive', $recent->json('data.*.product.status'));
        $this->assertContains('inactive', $older->json('data.*.product.status'));
    }

    /**
     * @return array<string, array{revenue: string, units_sold: int}>
     */
    private function expectedByProduct(ReportingPeriod $period): array
    {
        return TransactionItem::query()
            ->whereHas('transaction', fn ($transaction) => $transaction
                ->where('organization_id', $this->organization->id)
                ->where('status', 'paid')
                ->where('occurred_at', '>=', $period->startUtc())
                ->where('occurred_at', '<', $period->endUtc()))
            ->get(['product_id', 'quantity', 'line_total'])
            ->groupBy('product_id')
            ->map(fn ($items) => [
                'revenue' => Money::fromCents($items->sum(fn (TransactionItem $item): int => Money::toCents($item->line_total))),
                'units_sold' => $items->sum('quantity'),
            ])
            ->all();
    }

    private function fetch(string $uri): TestResponse
    {
        return $this->actingAs($this->owner)
            ->withHeader('X-Organization-Id', $this->organization->id)
            ->getJson($uri);
    }
}
