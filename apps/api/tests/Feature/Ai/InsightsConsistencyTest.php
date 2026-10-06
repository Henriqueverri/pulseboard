<?php

namespace Tests\Feature\Ai;

use App\Data\Ai\AiContext;
use App\Data\Ai\PeriodSummaryContext;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\Insights\PeriodSummaryContextBuilder;
use App\Support\Analytics\ReportingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Ai\Concerns\InteractsWithInsights;
use Tests\TestCase;

/**
 * Every number an insight can show (and every number sent to the model) is the
 * number the dashboard and analytics endpoints return for the same period, over
 * the demo dataset. Insights never have their own metric definitions.
 */
class InsightsConsistencyTest extends TestCase
{
    use InteractsWithInsights, RefreshDatabase;

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
     * @return array<string, array{0: int}>
     */
    public static function periods(): array
    {
        return [
            'last 7 days' => [7],
            'last 30 days' => [30],
            'last 90 days' => [90],
            'last 180 days' => [180],
        ];
    }

    #[DataProvider('periods')]
    public function test_the_catalog_and_the_model_context_match_the_analytics_endpoints(int $days): void
    {
        $period = ReportingPeriod::lastDays($days, $this->organization->timezone);
        $context = $this->build($period);
        $query = "from={$period->from()}&to={$period->to()}";

        $dashboard = $this->fetch("/api/v1/dashboard?{$query}")->json('data');
        $products = $this->fetch("/api/v1/analytics/products?{$query}&limit=5&sort=revenue")->json();
        $customers = $this->fetch("/api/v1/analytics/customers?{$query}")->json('summary');
        $statuses = collect($this->fetch("/api/v1/analytics/transactions?{$query}")->json('data'))->keyBy('status');

        $expected = [
            'kpi.revenue' => $dashboard['revenue'],
            'kpi.orders' => $dashboard['orders'],
            'kpi.average_order_value' => $dashboard['average_order_value'],
            'kpi.customers' => $dashboard['customers'],
            'customers.total' => $customers['total_customers'],
            'customers.new' => $customers['new_customers'],
            'customers.returning' => $customers['returning_customers'],
            'products.units_sold' => $products['summary']['units_sold'],
            'products.products_sold' => $products['summary']['products_sold'],
            'status.paid' => $statuses['paid']['orders'],
            'status.refunded' => $statuses['refunded']['orders'],
            'status.pending' => $statuses['pending']['orders'],
            'status.canceled' => $statuses['canceled']['orders'],
        ];

        foreach ($products['data'] as $row) {
            $expected["product.{$row['rank']}"] = $row['revenue'];
            $this->assertSame($row['product']['name'], $context->catalog->get("product.{$row['rank']}")->label);
        }

        $this->assertGreaterThan(0, $dashboard['orders']['value']);
        $this->assertCount(5, $products['data']);
        $this->assertSame(array_keys($expected), $context->catalog->refs());
        $this->assertSame($dashboard['customers'], $customers['active_customers']);

        foreach ($expected as $ref => $comparison) {
            $this->assertSame($comparison, $context->catalog->get($ref)->comparison->jsonSerialize(), "catalog {$ref}");
            $this->assertSame(
                $comparison,
                array_intersect_key($context->data['metrics'][$ref], $comparison),
                "model context {$ref}",
            );
        }
    }

    #[DataProvider('periods')]
    public function test_the_revenue_series_sent_to_the_model_is_the_revenue_endpoint_series(int $days): void
    {
        $period = ReportingPeriod::lastDays($days, $this->organization->timezone);
        $context = $this->build($period);
        $granularity = $context->data['revenue_series']['granularity'];

        $series = $this->fetch("/api/v1/analytics/revenue?from={$period->from()}&to={$period->to()}&granularity={$granularity}")->json('data');

        $this->assertSame(PeriodSummaryContextBuilder::granularityFor($period)->value, $granularity);
        $this->assertSame(
            array_map(fn (array $bucket): array => [
                'from' => $bucket['from'],
                'to' => $bucket['to'],
                'revenue' => $bucket['revenue'],
                'orders' => $bucket['orders'],
            ], $series),
            $context->data['revenue_series']['buckets'],
        );
        $this->assertLessThanOrEqual(31, count($series), 'the series stays compact');
    }

    public function test_the_numbers_in_a_generated_summary_are_the_dashboard_numbers(): void
    {
        config(['ai.enabled' => true]);
        $this->organization->enableInsights($this->owner);
        $this->scriptedProvider()->pushOutput($this->summaryOutput());

        $dashboard = $this->fetch('/api/v1/dashboard')->json('data');

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Organization-Id', $this->organization->id)
            ->postJson('/api/v1/insights/period-summary')
            ->assertOk();

        $evidence = $response->json('data.findings.0.evidence');
        $this->assertSame(['kpi.revenue', 'kpi.orders'], array_column($evidence, 'ref'));
        $this->assertSame($dashboard['revenue'], array_intersect_key($evidence[0], $dashboard['revenue']));
        $this->assertSame($dashboard['orders'], array_intersect_key($evidence[1], $dashboard['orders']));
    }

    private function build(ReportingPeriod $period): PeriodSummaryContext
    {
        return app(PeriodSummaryContextBuilder::class)->build(AiContext::for($this->organization, $this->owner, $period));
    }

    private function fetch(string $uri): TestResponse
    {
        return $this->actingAs($this->owner)
            ->withHeader('X-Organization-Id', $this->organization->id)
            ->getJson($uri)
            ->assertOk();
    }
}
