<?php

namespace Tests\Feature\Api\Analytics;

use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

/**
 * Query budget of every analytics endpoint: a fixed number of aggregate queries, whatever
 * the volume of transactions, products or customers, the ranking limit, the period
 * length or the granularity.
 */
class AnalyticsQueryBudgetTest extends TestCase
{
    use InteractsWithOrganizationApi, RefreshDatabase;

    /**
     * Queries that read business tables, as opposed to authentication and membership.
     */
    private const DOMAIN_TABLES = '/"(transactions|transaction_items|products|customers)"/';

    private const BUDGETS = [
        '/api/v1/dashboard' => 1,
        '/api/v1/analytics/revenue' => 2,
        '/api/v1/analytics/products' => 3,
        '/api/v1/analytics/customers' => 4,
        '/api/v1/analytics/transactions' => 1,
    ];

    private const PERIODS = [
        'from=2026-09-30&to=2026-09-30',
        'from=2026-09-01&to=2026-09-30',
        'from=2025-10-01&to=2026-09-30',
        'from=2025-09-30&to=2026-09-30',
    ];

    private Organization $organization;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->owner = $this->memberOf($this->organization);

        $customer = Customer::factory()->for($this->organization)->create();
        $product = Product::factory()->for($this->organization)->create();

        $this->createTransaction($customer, [[$product, 1, '10.00']], TransactionStatus::Paid, '2026-09-10 15:00:00');
    }

    public function test_every_endpoint_stays_within_its_budget_whatever_the_volume(): void
    {
        $few = $this->measure($this->uris());

        $this->seedVolume();

        $many = $this->measure($this->uris());

        foreach ($this->uris() as $uri) {
            $budget = self::BUDGETS[strtok($uri, '?')];

            $this->assertSame($budget, $few[$uri]['domain'], "{$uri} with little data");
            $this->assertSame($budget, $many[$uri]['domain'], "{$uri} with many records");
            $this->assertSame($few[$uri]['total'], $many[$uri]['total'], "{$uri} total queries, including auth and membership");
        }
    }

    public function test_transaction_status_reads_only_the_transactions_table_once(): void
    {
        $this->seedVolume();

        foreach (self::PERIODS as $period) {
            $queries = $this->domainQueries("/api/v1/analytics/transactions?{$period}");

            $this->assertCount(1, $queries, $period);
            $this->assertStringContainsString('group by', $queries[0]);
            $this->assertStringNotContainsString('"transaction_items"', $queries[0]);
            $this->assertStringNotContainsString('"customers"', $queries[0]);
        }
    }

    public function test_revenue_never_runs_one_query_per_bucket(): void
    {
        $this->seedVolume();

        foreach (['day', 'week', 'month'] as $granularity) {
            $queries = $this->domainQueries("/api/v1/analytics/revenue?from=2025-09-30&to=2026-09-30&granularity={$granularity}");

            $this->assertCount(2, $queries, $granularity);
            $this->assertCount(1, array_filter($queries, fn (string $sql) => str_contains($sql, 'group by')), $granularity);
        }
    }

    /**
     * @return list<string>
     */
    private function uris(): array
    {
        $uris = [];

        foreach (self::PERIODS as $period) {
            $uris[] = "/api/v1/dashboard?{$period}";
            $uris[] = "/api/v1/analytics/transactions?{$period}";

            foreach (['day', 'week', 'month'] as $granularity) {
                $uris[] = "/api/v1/analytics/revenue?{$period}&granularity={$granularity}";
            }

            foreach ([1, 10, 50] as $limit) {
                $uris[] = "/api/v1/analytics/products?{$period}&limit={$limit}&sort=revenue";
                $uris[] = "/api/v1/analytics/products?{$period}&limit={$limit}&sort=units_sold";
                $uris[] = "/api/v1/analytics/customers?{$period}&limit={$limit}&sort=revenue";
                $uris[] = "/api/v1/analytics/customers?{$period}&limit={$limit}&sort=orders";
            }
        }

        return $uris;
    }

    /**
     * 60 customers and 30 products trading over a year, in every status, with multi-item sales.
     */
    private function seedVolume(): void
    {
        $products = Product::factory()->for($this->organization)->count(30)->create()->all();
        $customers = Customer::factory()->for($this->organization)->count(60)->create();
        $statuses = TransactionStatus::cases();

        foreach ($customers->values() as $n => $customer) {
            foreach ([0, 5, 40, 200] as $offset) {
                $this->createTransaction(
                    $customer,
                    [[$products[$n % 30], 1, '10.00'], [$products[($n + $offset + 1) % 30], 2, '5.00']],
                    $statuses[($n + $offset) % count($statuses)],
                    CarbonImmutable::parse('2026-09-30 15:00:00')->subDays(($n + $offset) % 365)->toDateTimeString(),
                );
            }
        }
    }

    /**
     * @param  list<string>  $uris
     * @return array<string, array{domain: int, total: int}>
     */
    private function measure(array $uris): array
    {
        $counts = [];

        foreach ($uris as $uri) {
            $queries = $this->queries($uri);

            $counts[$uri] = [
                'domain' => count(array_filter($queries, fn (string $sql) => preg_match(self::DOMAIN_TABLES, $sql) === 1)),
                'total' => count($queries),
            ];
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    private function domainQueries(string $uri): array
    {
        return array_values(array_filter($this->queries($uri), fn (string $sql) => preg_match(self::DOMAIN_TABLES, $sql) === 1));
    }

    /**
     * SQL of every query run during one request.
     *
     * @return list<string>
     */
    private function queries(string $uri): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingInOrganization($this->owner, $this->organization)->getJson($uri)->assertOk();

        $queries = collect(DB::getQueryLog())->pluck('query')->values()->all();

        DB::disableQueryLog();

        return $queries;
    }
}
