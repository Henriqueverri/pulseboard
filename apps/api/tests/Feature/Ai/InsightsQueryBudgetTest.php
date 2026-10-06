<?php

namespace Tests\Feature\Ai;

use App\Data\Ai\AiContext;
use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\Insights\PeriodSummaryContextBuilder;
use App\Support\Analytics\ReportingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Ai\Concerns\InteractsWithInsights;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

/**
 * The summary context costs a fixed number of aggregate queries, the sum of the
 * analytics services it reuses (dashboard 1 + revenue 2 + products 3 +
 * customers 4 + statuses 1), whatever the volume or the period length.
 */
class InsightsQueryBudgetTest extends TestCase
{
    use InteractsWithInsights, InteractsWithOrganizationApi, RefreshDatabase;

    private const CONTEXT_BUDGET = 11;

    private const DOMAIN_TABLES = '/"(transactions|transaction_items|products|customers)"/';

    private const PERIODS = [
        ['2026-09-30', '2026-09-30'],
        ['2026-09-01', '2026-09-30'],
        ['2026-04-01', '2026-09-30'],
        ['2025-09-30', '2026-09-30'],
    ];

    private Organization $organization;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-01 12:00:00');
        $this->organization = Organization::factory()->create();
        $this->owner = $this->memberOf($this->organization);

        $customer = Customer::factory()->for($this->organization)->create();
        $product = Product::factory()->for($this->organization)->create();
        $this->createTransaction($customer, [[$product, 1, '10.00']], TransactionStatus::Paid, '2026-09-10 15:00:00');
    }

    public function test_the_context_builder_stays_within_its_budget_whatever_the_volume(): void
    {
        $few = array_map($this->contextQueries(...), self::PERIODS);

        $this->seedVolume();

        $many = array_map($this->contextQueries(...), self::PERIODS);

        foreach (self::PERIODS as $index => [$from, $to]) {
            $this->assertCount(self::CONTEXT_BUDGET, $few[$index], "{$from}..{$to} with little data");
            $this->assertCount(self::CONTEXT_BUDGET, $many[$index], "{$from}..{$to} with many records");
        }
    }

    public function test_reading_and_serving_a_cached_summary_add_only_ai_table_queries(): void
    {
        config(['ai.enabled' => true]);
        $this->organization->enableInsights($this->owner);
        $this->scriptedProvider();
        $this->seedVolume();

        $query = 'from=2026-09-01&to=2026-09-30';
        $this->actingInOrganization($this->owner, $this->organization)->postJson('/api/v1/insights/period-summary?'.$query)->assertOk();

        $read = $this->requestQueries('get', "/api/v1/insights/period-summary?{$query}");
        $cacheHit = $this->requestQueries('post', "/api/v1/insights/period-summary?{$query}");

        $this->assertCount(self::CONTEXT_BUDGET, $this->domain($read));
        $this->assertCount(self::CONTEXT_BUDGET, $this->domain($cacheHit));
        $this->assertCount(1, preg_grep('/from "ai_insights"/', $read), 'one cache lookup');
        $this->assertSame([], preg_grep('/"ai_runs"/', $read), 'reading writes no run');
        $this->assertCount(1, preg_grep('/insert into "ai_runs"/', $cacheHit), 'one cache_hit run');
    }

    /**
     * @return list<string>
     */
    private function contextQueries(array $period): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        app(PeriodSummaryContextBuilder::class)->build(AiContext::for(
            $this->organization,
            $this->owner,
            ReportingPeriod::fromDates($period[0], $period[1], $this->organization->timezone),
        ));

        $queries = collect(DB::getQueryLog())->pluck('query')->all();
        DB::disableQueryLog();

        return $this->domain($queries);
    }

    /**
     * @return list<string>
     */
    private function requestQueries(string $method, string $uri): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingInOrganization($this->owner, $this->organization)->json($method, $uri)->assertOk();

        $queries = collect(DB::getQueryLog())->pluck('query')->all();
        DB::disableQueryLog();

        return $queries;
    }

    /**
     * @param  list<string>  $queries
     * @return list<string>
     */
    private function domain(array $queries): array
    {
        return array_values(array_filter($queries, fn (string $sql) => preg_match(self::DOMAIN_TABLES, $sql) === 1));
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
}
