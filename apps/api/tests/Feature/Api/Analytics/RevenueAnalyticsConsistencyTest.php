<?php

namespace Tests\Feature\Api\Analytics;

use App\Models\Organization;
use App\Models\User;
use App\Support\Analytics\Granularity;
use App\Support\Analytics\ReportingPeriod;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Checks the revenue series against the dashboard and the shape of the demo dataset.
 */
class RevenueAnalyticsConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private ReportingPeriod $history;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->organization = Organization::query()->sole();
        $this->owner = User::query()->where('email', 'test@example.com')->sole();
        $this->history = ReportingPeriod::lastDays(90, $this->organization->timezone);
    }

    public function test_series_totals_match_the_summary_and_the_dashboard_for_every_granularity(): void
    {
        $query = "from={$this->history->from()}&to={$this->history->to()}";
        $dashboard = $this->fetch('/api/v1/dashboard?'.$query)->assertOk();

        foreach (Granularity::cases() as $granularity) {
            $response = $this->fetch("/api/v1/analytics/revenue?{$query}&granularity={$granularity->value}")->assertOk();

            $revenue = Money::fromCents(array_sum(array_map(Money::toCents(...), $response->json('data.*.revenue'))));
            $orders = array_sum($response->json('data.*.orders'));

            $this->assertCount(count($granularity->buckets($this->history)), $response->json('data'), $granularity->value);
            $this->assertSame($dashboard->json('data.revenue.value'), $revenue, $granularity->value);
            $this->assertSame($response->json('summary.revenue.value'), $revenue, $granularity->value);
            $this->assertSame($dashboard->json('data.orders.value'), $orders, $granularity->value);
            $this->assertSame($dashboard->json('data.revenue'), $response->json('summary.revenue'), $granularity->value);
            $this->assertSame($dashboard->json('data.orders'), $response->json('summary.orders'), $granularity->value);
        }
    }

    public function test_weekends_are_weaker_than_weekdays(): void
    {
        $days = $this->dailySeries();

        $weekend = $days->filter(fn (array $day) => CarbonImmutable::parse($day['bucket'])->isWeekend());
        $weekdays = $days->reject(fn (array $day) => CarbonImmutable::parse($day['bucket'])->isWeekend());

        $this->assertLessThan($weekdays->avg('orders'), $weekend->avg('orders'));
    }

    public function test_promo_week_stands_out_from_its_neighbours(): void
    {
        $orders = $this->dailySeries()->pluck('orders', 'bucket');
        $today = $this->history->toDate();

        $window = fn (int $newestDaysAgo) => collect(range($newestDaysAgo, $newestDaysAgo + 6))
            ->sum(fn (int $daysAgo) => $orders[$today->subDays($daysAgo)->toDateString()]);

        $promo = $window(32);

        $this->assertGreaterThan($window(25), $promo);
        $this->assertGreaterThan($window(39), $promo);
    }

    public function test_monthly_buckets_are_ordered_with_partial_edges(): void
    {
        $response = $this->fetch("/api/v1/analytics/revenue?from={$this->history->from()}&to={$this->history->to()}&granularity=month")->assertOk();
        $buckets = $response->json('data');

        $this->assertSame($this->history->from(), $buckets[0]['from']);
        $this->assertSame($this->history->to(), end($buckets)['to']);

        $keys = array_column($buckets, 'bucket');
        $sorted = $keys;
        sort($sorted);
        $this->assertSame($sorted, $keys);

        foreach ($keys as $key) {
            $this->assertStringEndsWith('-01', $key);
        }
    }

    /**
     * @return Collection<int, array{bucket: string, from: string, to: string, revenue: string, orders: int}>
     */
    private function dailySeries(): Collection
    {
        return collect($this->fetch("/api/v1/analytics/revenue?from={$this->history->from()}&to={$this->history->to()}")
            ->assertOk()
            ->json('data'));
    }

    private function fetch(string $uri): TestResponse
    {
        return $this->actingAs($this->owner)
            ->withHeader('X-Organization-Id', $this->organization->id)
            ->getJson($uri);
    }
}
