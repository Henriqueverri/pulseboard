<?php

namespace Tests\Feature\Api\Analytics;

use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Analytics\Granularity;
use App\Support\Analytics\ReportingPeriod;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Compares the public responses of every analytics endpoint and the transactions listing
 * for the same periods over the demo dataset, so no endpoint drifts to its own definition.
 */
class AnalyticsCrossEndpointConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->organization = Organization::query()->sole();
        $this->owner = User::query()->where('email', 'test@example.com')->sole();
    }

    /**
     * The demo history is 90 days long, so the period before the last 90 days is empty.
     *
     * @return array<string, array{0: int}>
     */
    public static function periods(): array
    {
        return [
            'last 7 days' => [7],
            'last 30 days' => [30],
            'last 90 days' => [90],
        ];
    }

    #[DataProvider('periods')]
    public function test_every_endpoint_shares_the_paid_sale_definitions(int $days): void
    {
        $query = $this->query($this->lastDays($days));

        $dashboard = $this->fetch("/api/v1/dashboard?{$query}")->assertOk()->json('data');
        $revenue = $this->fetch("/api/v1/analytics/revenue?{$query}")->assertOk()->json();
        $products = $this->fetch("/api/v1/analytics/products?{$query}")->assertOk()->json();
        $customers = $this->fetch("/api/v1/analytics/customers?{$query}")->assertOk()->json();
        $paid = $this->fetch("/api/v1/analytics/transactions?{$query}")->assertOk()->json('data.0');

        $this->assertGreaterThan(0, $dashboard['orders']['value']);
        $this->assertSame('paid', $paid['status']);

        $this->assertSame($dashboard['revenue'], $revenue['summary']['revenue'], 'revenue analytics');
        $this->assertSame($dashboard['revenue'], $products['summary']['revenue'], 'product analytics');
        $this->assertSame($dashboard['revenue'], $paid['revenue'], 'status analytics');
        $this->assertSame($dashboard['orders'], $revenue['summary']['orders'], 'revenue analytics');
        $this->assertSame($dashboard['orders'], $paid['orders'], 'status analytics');
        $this->assertSame($dashboard['customers'], $customers['summary']['active_customers'], 'customer analytics');

        foreach (['value', 'previous'] as $key) {
            $this->assertSame(
                $customers['summary']['active_customers'][$key],
                $customers['summary']['new_customers'][$key] + $customers['summary']['returning_customers'][$key],
                "new + returning = active ({$key})",
            );
        }
    }

    #[DataProvider('periods')]
    public function test_revenue_series_adds_up_to_the_dashboard_for_every_granularity(int $days): void
    {
        $query = $this->query($this->lastDays($days));
        $dashboard = $this->fetch("/api/v1/dashboard?{$query}")->assertOk()->json('data');

        foreach (Granularity::cases() as $granularity) {
            $series = $this->fetch("/api/v1/analytics/revenue?{$query}&granularity={$granularity->value}")->assertOk()->json('data');

            $this->assertSame($dashboard['revenue']['value'], $this->sum(array_column($series, 'revenue')), $granularity->value);
            $this->assertSame($dashboard['orders']['value'], array_sum(array_column($series, 'orders')), $granularity->value);
        }
    }

    #[DataProvider('periods')]
    public function test_status_distribution_adds_up_to_the_transactions_listing(int $days): void
    {
        $period = $this->lastDays($days);
        $rows = $this->fetch("/api/v1/analytics/transactions?{$this->query($period)}")->assertOk()->json('data');

        foreach (['value' => $period, 'previous' => $period->previous()] as $key => $expected) {
            $listed = $this->listed($expected);
            $paidListed = $this->listed($expected, 'status=paid');
            $amounts = Transaction::query()
                ->where('organization_id', $this->organization->id)
                ->where('occurred_at', '>=', $expected->startUtc())
                ->where('occurred_at', '<', $expected->endUtc())
                ->pluck('total_amount')
                ->all();

            $this->assertSame($listed, array_sum(array_map(fn (array $row) => $row['orders'][$key], $rows)), "orders {$key}");
            $this->assertSame($paidListed, $rows[0]['orders'][$key], "paid orders {$key}");
            $this->assertSame($this->sum($amounts), $this->sum(array_map(fn (array $row) => $row['revenue'][$key], $rows)), "revenue {$key}");

            if ($listed === 0) {
                $this->assertSame([null, null, null, null], array_map(fn (array $row) => $row['percentage'][$key], $rows), "percentage {$key}");

                continue;
            }

            $this->assertLessThan($listed, $paidListed, "the dashboard definition excludes non-paid transactions ({$key})");
            $this->assertEqualsWithDelta(100.0, array_sum(array_map(fn (array $row) => $row['percentage'][$key], $rows)), 0.2, "percentage {$key}");

            foreach ($rows as $row) {
                $this->assertSame(round($row['orders'][$key] * 1000 / $listed) / 10, $row['percentage'][$key], "{$row['status']} percentage {$key}");
            }
        }
    }

    #[DataProvider('periods')]
    public function test_previous_values_match_a_request_for_the_previous_period(int $days): void
    {
        $period = $this->lastDays($days);
        $current = $this->query($period);
        $previous = $this->query($period->previous());

        $pairs = [
            'dashboard' => ['/api/v1/dashboard', 'data', ['revenue', 'orders', 'average_order_value', 'customers']],
            'revenue' => ['/api/v1/analytics/revenue', 'summary', ['revenue', 'orders']],
            'products' => ['/api/v1/analytics/products', 'summary', ['revenue', 'units_sold', 'products_sold']],
            'customers' => ['/api/v1/analytics/customers', 'summary', ['total_customers', 'active_customers', 'new_customers', 'returning_customers']],
        ];

        foreach ($pairs as $name => [$uri, $root, $metrics]) {
            $now = $this->fetch("{$uri}?{$current}")->assertOk();
            $before = $this->fetch("{$uri}?{$previous}")->assertOk();

            foreach ($metrics as $metric) {
                $this->assertSame($before->json("{$root}.{$metric}.value"), $now->json("{$root}.{$metric}.previous"), "{$name}.{$metric}");
            }
        }

        $now = $this->fetch("/api/v1/analytics/transactions?{$current}")->assertOk()->json('data');
        $before = $this->fetch("/api/v1/analytics/transactions?{$previous}")->assertOk()->json('data');

        foreach ($now as $index => $row) {
            foreach (['orders', 'revenue', 'percentage'] as $metric) {
                $this->assertSame($before[$index][$metric]['value'], $row[$metric]['previous'], "{$row['status']}.{$metric}");
            }
        }
    }

    public function test_full_rankings_add_up_to_the_shared_kpis(): void
    {
        $query = $this->query($this->lastDays(7));

        $dashboard = $this->fetch("/api/v1/dashboard?{$query}")->assertOk()->json('data');
        $products = $this->fetch("/api/v1/analytics/products?{$query}&limit=50")->assertOk()->json();
        $customers = $this->fetch("/api/v1/analytics/customers?{$query}&limit=50")->assertOk()->json();

        $this->assertLessThan(50, count($products['data']), 'the whole product ranking must fit in one response');
        $this->assertLessThan(50, count($customers['data']), 'the whole customer ranking must fit in one response');

        $this->assertSame($dashboard['revenue']['value'], $this->sum(array_map(fn (array $row) => $row['revenue']['value'], $products['data'])));
        $this->assertSame($products['summary']['units_sold']['value'], array_sum(array_map(fn (array $row) => $row['units_sold']['value'], $products['data'])));
        $this->assertSame($products['summary']['products_sold']['value'], count($products['data']));

        $this->assertSame($dashboard['revenue']['value'], $this->sum(array_map(fn (array $row) => $row['revenue']['value'], $customers['data'])));
        $this->assertSame($dashboard['orders']['value'], array_sum(array_map(fn (array $row) => $row['orders']['value'], $customers['data'])));
        $this->assertSame($dashboard['customers']['value'], count($customers['data']));
    }

    public function test_total_customers_match_the_customer_listing_at_the_end_of_the_period(): void
    {
        $listed = $this->fetch('/api/v1/customers?per_page=1')->assertOk()->json('meta.total');

        foreach ([7, 30, 90] as $days) {
            $this->fetch("/api/v1/analytics/customers?{$this->query($this->lastDays($days))}")
                ->assertOk()
                ->assertJsonPath('summary.total_customers.value', $listed);
        }
    }

    public function test_product_revenue_uses_the_sale_snapshot_instead_of_the_current_price(): void
    {
        $prices = collect($this->fetch('/api/v1/products?per_page=100')->assertOk()->json('data'))->pluck('price', 'id');
        $today = $this->lastDays(1)->toDate();

        $recent = $this->ranking($this->lastDays(30));
        $beforeRaise = $this->ranking(ReportingPeriod::fromDates(
            $today->subDays(89)->toDateString(),
            $today->subDays(50)->toDateString(),
            $this->organization->timezone,
        ));

        $atCurrentPrice = fn (array $row) => Money::fromCents(Money::toCents($prices[$row['product']['id']]) * $row['units_sold']['value']);

        foreach ($recent as $row) {
            $this->assertSame($atCurrentPrice($row), $row['revenue']['value'], "{$row['product']['name']} sold at today's price");
        }

        $repriced = array_filter($beforeRaise, fn (array $row) => $atCurrentPrice($row) !== $row['revenue']['value']);

        $this->assertNotEmpty($repriced, 'products sold before the price raise keep the old unit price');

        foreach ($repriced as $row) {
            $this->assertLessThan(Money::toCents($atCurrentPrice($row)), Money::toCents($row['revenue']['value']), $row['product']['name']);
        }
    }

    public function test_transactions_listing_follows_the_same_local_days_and_months_as_revenue_buckets(): void
    {
        $period = $this->lastDays(90);

        foreach (['day' => $this->lastDays(10), 'month' => $period] as $granularity => $range) {
            $buckets = $this->fetch("/api/v1/analytics/revenue?{$this->query($range)}&granularity={$granularity}")->assertOk()->json('data');

            foreach ($buckets as $bucket) {
                $this->assertSame(
                    $bucket['orders'],
                    $this->fetch("/api/v1/transactions?per_page=1&status=paid&from={$bucket['from']}&to={$bucket['to']}")->assertOk()->json('meta.total'),
                    "{$granularity} {$bucket['bucket']}",
                );
            }
        }
    }

    private function lastDays(int $days): ReportingPeriod
    {
        return ReportingPeriod::lastDays($days, $this->organization->timezone);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ranking(ReportingPeriod $period): array
    {
        $ranking = $this->fetch("/api/v1/analytics/products?{$this->query($period)}&limit=50")->assertOk()->json('data');

        $this->assertNotEmpty($ranking);
        $this->assertLessThan(50, count($ranking), 'the whole product ranking must fit in one response');

        return $ranking;
    }

    private function listed(ReportingPeriod $period, string $filters = ''): int
    {
        return $this->fetch("/api/v1/transactions?per_page=1&{$this->query($period)}".($filters === '' ? '' : "&{$filters}"))
            ->assertOk()
            ->json('meta.total');
    }

    /**
     * @param  array<int, string>  $amounts
     */
    private function sum(array $amounts): string
    {
        return Money::fromCents(array_sum(array_map(Money::toCents(...), $amounts)));
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
