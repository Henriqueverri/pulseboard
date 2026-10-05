<?php

namespace Tests\Feature\Api\Ingest;

use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Support\Analytics\Granularity;
use App\Support\Analytics\ReportingPeriod;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\Concerns\AssertsIngestionInvariants;
use Tests\Feature\Api\Concerns\InteractsWithIngestApi;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

/**
 * From the external system to the dashboard: ingested transactions live in the
 * same table and go through the same scopes, so every analytics endpoint
 * reflects them on the next read, and a status change moves them between the
 * paid and non-paid numbers (analytics reads the current status).
 */
class IngestAnalyticsTest extends TestCase
{
    use AssertsIngestionInvariants, InteractsWithIngestApi, InteractsWithOrganizationApi, RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private string $plainTextKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['currency' => 'BRL']);
        $this->owner = $this->memberOf($this->organization);
        [, $this->plainTextKey] = $this->issueApiKey($this->organization);
        Product::factory()->for($this->organization)->create(['sku' => 'SKU-A']);
        Product::factory()->for($this->organization)->create(['sku' => 'SKU-B']);
    }

    public function test_an_ingested_paid_sale_shows_up_in_every_endpoint(): void
    {
        $this->ingest('order-1', 'paid', ['SKU-A' => [2, '49.90'], 'SKU-B' => [1, '10.00']]);
        $period = $this->period();

        $this->fetch("/api/v1/dashboard?{$period}")
            ->assertJsonPath('data.revenue.value', '109.80')
            ->assertJsonPath('data.orders.value', 1)
            ->assertJsonPath('data.customers.value', 1);

        $this->fetch("/api/v1/analytics/revenue?{$period}&granularity=day")
            ->assertJsonPath('summary.revenue.value', '109.80')
            ->assertJsonPath('summary.orders.value', 1);

        $this->fetch("/api/v1/analytics/products?{$period}&sort=revenue")
            ->assertJsonPath('data.*.product.sku', ['SKU-A', 'SKU-B'])
            ->assertJsonPath('data.*.revenue.value', ['99.80', '10.00'])
            ->assertJsonPath('data.*.units_sold.value', [2, 1])
            ->assertJsonPath('summary.revenue.value', '109.80');

        $this->fetch("/api/v1/analytics/customers?{$period}")
            ->assertJsonPath('summary.active_customers.value', 1)
            ->assertJsonPath('data.0.revenue.value', '109.80');

        $this->assertSame(['orders' => 1, 'revenue' => '109.80'], $this->statusRow('paid'));

        $this->fetch('/api/v1/transactions?q=order-1')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.total_amount', '109.80');
    }

    /**
     * pending is not revenue; pending -> paid makes it revenue; paid -> refunded
     * takes it out again. Each step is one status change in the history.
     */
    public function test_the_lifecycle_moves_a_sale_in_and_out_of_revenue(): void
    {
        $this->ingest('order-1', 'pending', ['SKU-A' => [1, '25.00']]);

        $this->assertRevenue('0.00', 0);
        $this->assertSame(['orders' => 1, 'revenue' => '25.00'], $this->statusRow('pending'));

        $this->changeStatus('order-1', 'paid');

        $this->assertRevenue('25.00', 1);
        $this->assertSame(['orders' => 0, 'revenue' => '0.00'], $this->statusRow('pending'));
        $this->assertSame(['orders' => 1, 'revenue' => '25.00'], $this->statusRow('paid'));

        $this->changeStatus('order-1', 'refunded');

        $this->assertRevenue('0.00', 0);
        $this->assertSame(['orders' => 0, 'revenue' => '0.00'], $this->statusRow('paid'));
        $this->assertSame(['orders' => 1, 'revenue' => '25.00'], $this->statusRow('refunded'));
        $this->assertIngestionInvariants();
    }

    /**
     * Ingested transactions mixed into the demo dataset keep every endpoint on
     * the same definitions, as AnalyticsCrossEndpointConsistencyTest checks for seeded data.
     */
    public function test_endpoints_stay_consistent_with_ingested_data_mixed_into_the_demo(): void
    {
        $this->seed();
        $this->organization = Organization::query()->where('slug', '!=', $this->organization->slug)->sole();
        $this->owner = User::query()->where('email', 'test@example.com')->sole();
        [, $this->plainTextKey] = $this->issueApiKey($this->organization);
        $skus = Product::query()->where('organization_id', $this->organization->id)->orderBy('sku')->limit(3)->pluck('sku')->all();

        $this->ingest('ext-paid', 'paid', [$skus[0] => [3, '19.90'], $skus[1] => [1, '5.00']]);
        $this->ingest('ext-pending', 'pending', [$skus[2] => [1, '7.50']]);
        $this->ingest('ext-to-paid', 'pending', [$skus[0] => [1, '19.90']]);
        $this->changeStatus('ext-to-paid', 'paid');
        $this->ingest('ext-refunded', 'paid', [$skus[1] => [2, '5.00']]);
        $this->changeStatus('ext-refunded', 'refunded');
        $this->ingest('ext-canceled', 'pending', [$skus[2] => [1, '7.50']]);
        $this->changeStatus('ext-canceled', 'canceled');

        foreach ([1, 7, 30] as $days) {
            $period = ReportingPeriod::lastDays($days, $this->organization->timezone);
            $query = "from={$period->from()}&to={$period->to()}";

            $dashboard = $this->fetch("/api/v1/dashboard?{$query}")->json('data');
            $revenue = $this->fetch("/api/v1/analytics/revenue?{$query}")->json('summary');
            $products = $this->fetch("/api/v1/analytics/products?{$query}")->json('summary');
            $customers = $this->fetch("/api/v1/analytics/customers?{$query}")->json('summary');
            $paid = $this->fetch("/api/v1/analytics/transactions?{$query}")->json('data.0');

            $this->assertSame('paid', $paid['status']);
            $this->assertSame($dashboard['revenue'], $revenue['revenue'], "{$days} days: revenue analytics");
            $this->assertSame($dashboard['revenue'], $products['revenue'], "{$days} days: product analytics");
            $this->assertSame($dashboard['revenue'], $paid['revenue'], "{$days} days: status analytics");
            $this->assertSame($dashboard['orders'], $revenue['orders'], "{$days} days: revenue orders");
            $this->assertSame($dashboard['orders'], $paid['orders'], "{$days} days: status orders");
            $this->assertSame($dashboard['customers'], $customers['active_customers'], "{$days} days: customers");
            $this->assertSame($dashboard['orders']['value'], $this->fetch("/api/v1/transactions?per_page=1&status=paid&{$query}")->json('meta.total'), "{$days} days: listing");

            foreach (Granularity::cases() as $granularity) {
                $series = $this->fetch("/api/v1/analytics/revenue?{$query}&granularity={$granularity->value}")->json('data');

                $this->assertSame(
                    $dashboard['revenue']['value'],
                    Money::fromCents(array_sum(array_map(Money::toCents(...), array_column($series, 'revenue')))),
                    "{$days} days: {$granularity->value} series",
                );
            }
        }

        $this->assertIngestionInvariants();
    }

    private function assertRevenue(string $revenue, int $orders): void
    {
        $this->fetch("/api/v1/dashboard?{$this->period()}")
            ->assertJsonPath('data.revenue.value', $revenue)
            ->assertJsonPath('data.orders.value', $orders);

        $this->fetch("/api/v1/analytics/revenue?{$this->period()}")
            ->assertJsonPath('summary.revenue.value', $revenue)
            ->assertJsonPath('summary.orders.value', $orders);
    }

    /**
     * @return array{orders: int, revenue: string}
     */
    private function statusRow(string $status): array
    {
        $row = collect($this->fetch("/api/v1/analytics/transactions?{$this->period()}")->json('data'))->firstWhere('status', $status);

        return ['orders' => $row['orders']['value'], 'revenue' => $row['revenue']['value']];
    }

    /**
     * Yesterday and today in the organization's timezone: the sales happened a minute ago.
     */
    private function period(): string
    {
        $today = now($this->organization->timezone);

        return "from={$today->copy()->subDay()->toDateString()}&to={$today->toDateString()}";
    }

    /**
     * @param  array<string, array{0: int, 1: string}>  $items  SKU => [quantity, unit price]
     */
    private function ingest(string $externalId, string $status, array $items): void
    {
        $lines = [];

        foreach ($items as $sku => [$quantity, $unitPrice]) {
            $lines[] = ['sku' => $sku, 'quantity' => $quantity, 'unit_price' => $unitPrice];
        }

        $this->asIntegration($this->plainTextKey)
            ->postJson('/api/v1/ingest/transactions', [
                'external_id' => $externalId,
                'status' => $status,
                'occurred_at' => now()->subMinutes(2)->toIso8601String(),
                'currency' => 'BRL',
                'customer' => [
                    'external_id' => "customer-{$externalId}",
                    'name' => 'Analytics Customer',
                    'email' => "customer-{$externalId}@example.com",
                ],
                'items' => $lines,
            ])
            ->assertCreated();
    }

    private function changeStatus(string $externalId, string $status): void
    {
        $this->asIntegration($this->plainTextKey)
            ->postJson("/api/v1/ingest/transactions/{$externalId}/status-changes", [
                'status' => $status,
                'occurred_at' => now()->subMinute()->toIso8601String(),
            ])
            ->assertCreated();
    }

    private function fetch(string $uri): TestResponse
    {
        $this->withHeaders(['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000'])->withoutHeader('Authorization');

        return $this->actingInOrganization($this->owner, $this->organization)->getJson($uri)->assertOk();
    }
}
