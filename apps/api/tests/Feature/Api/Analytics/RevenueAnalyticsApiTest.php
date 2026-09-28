<?php

namespace Tests\Feature\Api\Analytics;

use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

class RevenueAnalyticsApiTest extends TestCase
{
    use InteractsWithOrganizationApi, RefreshDatabase;

    private const SEPTEMBER = 'from=2026-09-01&to=2026-09-30';

    private Organization $organization;

    private User $owner;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->owner = $this->memberOf($this->organization);
        $this->customer = Customer::factory()->for($this->organization)->create();
        $this->product = Product::factory()->for($this->organization)->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // Contract

    public function test_returns_every_bucket_with_summary_and_meta(): void
    {
        $this->sale('100.00', '2026-09-02 15:00:00');
        $this->sale('30.40', '2026-09-15 15:00:00');
        $this->sale('50.00', '2026-08-25 15:00:00');

        $response = $this->revenue('from=2026-09-02&to=2026-09-16&granularity=week')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    ['bucket' => '2026-08-31', 'from' => '2026-09-02', 'to' => '2026-09-06', 'revenue' => '100.00', 'orders' => 1],
                    ['bucket' => '2026-09-07', 'from' => '2026-09-07', 'to' => '2026-09-13', 'revenue' => '0.00', 'orders' => 0],
                    ['bucket' => '2026-09-14', 'from' => '2026-09-14', 'to' => '2026-09-16', 'revenue' => '30.40', 'orders' => 1],
                ],
                'summary' => [
                    'revenue' => ['value' => '130.40', 'previous' => '50.00', 'change' => 160.8],
                    'orders' => ['value' => 2, 'previous' => 1, 'change' => 100.0],
                ],
                'meta' => [
                    'period' => ['from' => '2026-09-02', 'to' => '2026-09-16', 'days' => 15],
                    'previous_period' => ['from' => '2026-08-18', 'to' => '2026-09-01', 'days' => 15],
                    'timezone' => 'America/Sao_Paulo',
                    'currency' => 'BRL',
                    'granularity' => 'week',
                ],
            ]);

        $this->assertStringContainsString('"change":100.0', $response->getContent());
        $this->assertStringNotContainsString('organization_id', $response->getContent());
    }

    // Paid rule

    public function test_revenue_counts_only_paid_transactions(): void
    {
        $this->sale('100.00', '2026-09-10 15:00:00');
        $this->sale('70.00', '2026-09-10 15:00:00', TransactionStatus::Pending);
        $this->sale('30.00', '2026-09-10 15:00:00', TransactionStatus::Refunded);
        $this->sale('20.00', '2026-09-10 15:00:00', TransactionStatus::Canceled);

        $this->revenue('from=2026-09-10&to=2026-09-10')
            ->assertOk()
            ->assertJsonPath('data', [['bucket' => '2026-09-10', 'from' => '2026-09-10', 'to' => '2026-09-10', 'revenue' => '100.00', 'orders' => 1]])
            ->assertJsonPath('summary.revenue.value', '100.00')
            ->assertJsonPath('summary.orders.value', 1);
    }

    // Buckets

    public function test_daily_buckets_cover_every_day_in_order(): void
    {
        $this->sale('10.00', '2026-09-05 15:00:00');
        $this->sale('15.00', '2026-09-05 16:00:00');

        $response = $this->revenue(self::SEPTEMBER)->assertOk()->assertJsonCount(30, 'data');

        $expectedKeys = array_map(fn (int $day) => sprintf('2026-09-%02d', $day), range(1, 30));
        $this->assertSame($expectedKeys, $response->json('data.*.bucket'));
        $this->assertSame($expectedKeys, $response->json('data.*.from'));
        $this->assertSame($expectedKeys, $response->json('data.*.to'));
        $this->assertSame(['bucket' => '2026-09-05', 'from' => '2026-09-05', 'to' => '2026-09-05', 'revenue' => '25.00', 'orders' => 2], $response->json('data.4'));
        $this->assertSame('day', $response->json('meta.granularity'));
    }

    public function test_weekly_buckets_are_iso_weeks_with_partial_edges(): void
    {
        // 2026-09-02 and 2026-09-16 are Wednesdays.
        $this->sale('10.00', '2026-09-06 15:00:00'); // Sunday of the first week
        $this->sale('20.00', '2026-09-07 15:00:00'); // Monday of the second week

        $this->revenue('from=2026-09-02&to=2026-09-16&granularity=week')
            ->assertOk()
            ->assertJsonPath('data.*.bucket', ['2026-08-31', '2026-09-07', '2026-09-14'])
            ->assertJsonPath('data.0.from', '2026-09-02')
            ->assertJsonPath('data.2.to', '2026-09-16')
            ->assertJsonPath('data.*.revenue', ['10.00', '20.00', '0.00']);
    }

    public function test_monthly_buckets_cross_months_with_partial_edges(): void
    {
        $this->sale('10.00', '2026-01-31 15:00:00');
        $this->sale('20.00', '2026-02-14 15:00:00');
        $this->sale('40.00', '2026-03-15 15:00:00');
        $this->sale('80.00', '2026-03-16 15:00:00');

        $this->revenue('from=2026-01-31&to=2026-03-15&granularity=month')
            ->assertOk()
            ->assertJsonPath('data', [
                ['bucket' => '2026-01-01', 'from' => '2026-01-31', 'to' => '2026-01-31', 'revenue' => '10.00', 'orders' => 1],
                ['bucket' => '2026-02-01', 'from' => '2026-02-01', 'to' => '2026-02-28', 'revenue' => '20.00', 'orders' => 1],
                ['bucket' => '2026-03-01', 'from' => '2026-03-01', 'to' => '2026-03-15', 'revenue' => '40.00', 'orders' => 1],
            ]);
    }

    public function test_buckets_cross_the_year_boundary(): void
    {
        $this->sale('10.00', '2026-12-31 15:00:00');
        $this->sale('20.00', '2027-01-04 15:00:00');

        $this->revenue('from=2026-12-28&to=2027-01-10&granularity=week')
            ->assertOk()
            ->assertJsonPath('data.*.bucket', ['2026-12-28', '2027-01-04'])
            ->assertJsonPath('data.*.revenue', ['10.00', '20.00']);

        $this->revenue('from=2026-12-28&to=2027-01-10&granularity=month')
            ->assertOk()
            ->assertJsonPath('data', [
                ['bucket' => '2026-12-01', 'from' => '2026-12-28', 'to' => '2026-12-31', 'revenue' => '10.00', 'orders' => 1],
                ['bucket' => '2027-01-01', 'from' => '2027-01-01', 'to' => '2027-01-10', 'revenue' => '20.00', 'orders' => 1],
            ]);
    }

    public function test_granularity_larger_than_the_period_yields_one_partial_bucket(): void
    {
        $this->sale('10.00', '2026-09-11 15:00:00');

        $this->revenue('from=2026-09-10&to=2026-09-12&granularity=month')
            ->assertOk()
            ->assertJsonPath('data', [['bucket' => '2026-09-01', 'from' => '2026-09-10', 'to' => '2026-09-12', 'revenue' => '10.00', 'orders' => 1]]);
    }

    // Empty buckets

    public function test_days_without_sales_stay_in_the_series_as_zero(): void
    {
        $this->sale('10.00', '2026-09-10 15:00:00');
        $this->sale('30.00', '2026-09-12 15:00:00');

        $this->revenue('from=2026-09-10&to=2026-09-12')
            ->assertOk()
            ->assertJsonPath('data.*.bucket', ['2026-09-10', '2026-09-11', '2026-09-12'])
            ->assertJsonPath('data.1', ['bucket' => '2026-09-11', 'from' => '2026-09-11', 'to' => '2026-09-11', 'revenue' => '0.00', 'orders' => 0]);
    }

    public function test_period_without_sales_returns_all_zero_buckets(): void
    {
        $response = $this->revenue(self::SEPTEMBER.'&granularity=week')->assertOk();

        $this->assertCount(5, $response->json('data'));
        $this->assertSame(['0.00'], array_values(array_unique($response->json('data.*.revenue'))));
        $this->assertSame([0], array_values(array_unique($response->json('data.*.orders'))));
        $response->assertJsonPath('summary', [
            'revenue' => ['value' => '0.00', 'previous' => '0.00', 'change' => 0.0],
            'orders' => ['value' => 0, 'previous' => 0, 'change' => 0.0],
        ]);
    }

    // Defaults

    public function test_defaults_to_daily_buckets_over_the_last_30_days_in_the_organization_timezone(): void
    {
        // 01:30 UTC on Oct 1st is still Sep 30th in São Paulo.
        Carbon::setTestNow('2026-10-01 01:30:00');
        $this->sale('25.00', '2026-10-01 01:00:00');

        $this->revenue()
            ->assertOk()
            ->assertJsonCount(30, 'data')
            ->assertJsonPath('meta.granularity', 'day')
            ->assertJsonPath('meta.period', ['from' => '2026-09-01', 'to' => '2026-09-30', 'days' => 30])
            ->assertJsonPath('data.29.bucket', '2026-09-30')
            ->assertJsonPath('data.29.revenue', '25.00');
    }

    // Timezone

    public function test_sao_paulo_midnight_decides_the_daily_bucket(): void
    {
        $this->sale('10.00', '2026-09-01 02:59:59'); // Aug 31st 23:59:59 local
        $this->sale('20.00', '2026-09-01 03:00:00'); // Sep 1st 00:00 local

        $this->revenue('from=2026-08-31&to=2026-09-01')
            ->assertOk()
            ->assertJsonPath('data.*.bucket', ['2026-08-31', '2026-09-01'])
            ->assertJsonPath('data.*.revenue', ['10.00', '20.00']);

        $this->revenue('from=2026-09-01&to=2026-09-01')
            ->assertOk()
            ->assertJsonPath('data.*.revenue', ['20.00'])
            ->assertJsonPath('summary.revenue.previous', '10.00');
    }

    public function test_sao_paulo_local_calendar_decides_weekly_and_monthly_buckets(): void
    {
        $this->sale('10.00', '2026-09-14 02:30:00'); // Sunday Sep 13th 23:30 local: week of Sep 7th
        $this->sale('20.00', '2026-09-14 03:00:00'); // Monday Sep 14th 00:00 local
        $this->sale('40.00', '2026-09-01 01:00:00'); // Aug 31st 22:00 local: August

        $this->revenue('from=2026-09-07&to=2026-09-20&granularity=week')
            ->assertOk()
            ->assertJsonPath('data.*.bucket', ['2026-09-07', '2026-09-14'])
            ->assertJsonPath('data.*.revenue', ['10.00', '20.00']);

        $this->revenue('from=2026-08-01&to=2026-09-30&granularity=month')
            ->assertOk()
            ->assertJsonPath('data.*.bucket', ['2026-08-01', '2026-09-01'])
            ->assertJsonPath('data.*.revenue', ['40.00', '30.00']);
    }

    public function test_lisbon_midnight_decides_the_daily_bucket(): void
    {
        $this->organization->update(['timezone' => 'Europe/Lisbon']);

        // Lisbon is UTC+1 in September.
        $this->sale('10.00', '2026-09-09 22:59:59'); // Sep 9th 23:59:59 local
        $this->sale('20.00', '2026-09-09 23:00:00'); // Sep 10th 00:00 local

        $this->revenue('from=2026-09-09&to=2026-09-10')
            ->assertOk()
            ->assertJsonPath('meta.timezone', 'Europe/Lisbon')
            ->assertJsonPath('data.*.bucket', ['2026-09-09', '2026-09-10'])
            ->assertJsonPath('data.*.revenue', ['10.00', '20.00']);
    }

    public function test_lisbon_daily_buckets_follow_daylight_saving_time_on_postgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Exact DST bucketing needs the PostgreSQL timezone database.');
        }

        $this->organization->update(['timezone' => 'Europe/Lisbon']);

        // Lisbon switches from UTC+0 to UTC+1 on 2026-03-29 at 01:00 UTC.
        $this->sale('10.00', '2026-03-28 23:30:00'); // Mar 28th 23:30 WET
        $this->sale('20.00', '2026-03-29 00:30:00'); // Mar 29th 00:30 WET
        $this->sale('40.00', '2026-03-29 23:30:00'); // Mar 30th 00:30 WEST
        $this->sale('80.00', '2026-03-30 22:30:00'); // Mar 30th 23:30 WEST

        $response = $this->revenue('from=2026-03-28&to=2026-03-30')
            ->assertOk()
            ->assertJsonPath('data.*.bucket', ['2026-03-28', '2026-03-29', '2026-03-30'])
            ->assertJsonPath('data.*.revenue', ['10.00', '20.00', '120.00']);

        $this->assertSame($response->json('summary.revenue.value'), $this->sumRevenue($response));
    }

    // Tenant isolation and access

    public function test_other_organization_revenue_is_never_included(): void
    {
        $foreign = Organization::factory()->create();
        $foreignOwner = $this->memberOf($foreign);
        $foreignCustomer = Customer::factory()->for($foreign)->create();
        $foreignProduct = Product::factory()->for($foreign)->create();
        $this->createTransaction($foreignCustomer, [[$foreignProduct, 1, '900.00']], occurredAt: '2026-09-10 15:00:00');
        $this->createTransaction($foreignCustomer, [[$foreignProduct, 1, '700.00']], occurredAt: '2026-09-09 15:00:00');

        $this->sale('100.00', '2026-09-10 15:00:00');

        $this->revenue('from=2026-09-10&to=2026-09-10')
            ->assertOk()
            ->assertJsonPath('data.*.revenue', ['100.00'])
            ->assertJsonPath('summary.revenue', ['value' => '100.00', 'previous' => '0.00', 'change' => null]);

        $this->actingInOrganization($foreignOwner, $foreign)
            ->getJson('/api/v1/analytics/revenue?from=2026-09-10&to=2026-09-10')
            ->assertOk()
            ->assertJsonPath('data.*.revenue', ['900.00'])
            ->assertJsonPath('summary.revenue.previous', '700.00');
    }

    public function test_organization_header_without_membership_is_forbidden(): void
    {
        $this->actingInOrganization($this->owner, Organization::factory()->create())
            ->getJson('/api/v1/analytics/revenue')
            ->assertForbidden();
    }

    public function test_guest_receives_401(): void
    {
        $this->withHeader('X-Organization-Id', $this->organization->id)
            ->getJson('/api/v1/analytics/revenue')
            ->assertUnauthorized();
    }

    public function test_member_role_can_read_revenue_analytics(): void
    {
        $member = $this->memberOf($this->organization, Organization::ROLE_MEMBER);
        $this->sale('10.00', '2026-09-10 15:00:00');

        $this->actingInOrganization($member, $this->organization)
            ->getJson('/api/v1/analytics/revenue?from=2026-09-10&to=2026-09-10')
            ->assertOk()
            ->assertJsonPath('data.0.revenue', '10.00');
    }

    // Validation

    public function test_rejects_invalid_granularity_and_periods(): void
    {
        $this->revenue(self::SEPTEMBER.'&granularity=hour')->assertUnprocessable()->assertJsonValidationErrors(['granularity']);
        $this->revenue('from=01/09/2026&to=2026-02-30')->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
        $this->revenue('from=2026-09-01')->assertUnprocessable()->assertJsonValidationErrors(['to']);
        $this->revenue('from=2026-09-10&to=2026-09-01')->assertUnprocessable()->assertJsonValidationErrors(['to']);
        $this->revenue('from=2025-01-01&to=2026-01-02')->assertUnprocessable()->assertJsonValidationErrors(['to']);
    }

    public function test_organization_id_is_rejected_and_timezone_parameter_is_ignored(): void
    {
        $this->revenue(self::SEPTEMBER.'&organization_id='.Organization::factory()->create()->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['organization_id']);

        $this->sale('10.00', '2026-09-01 02:30:00'); // Aug 31st 23:30 local

        $this->revenue('from=2026-08-31&to=2026-09-01&timezone=UTC')
            ->assertOk()
            ->assertJsonPath('meta.timezone', 'America/Sao_Paulo')
            ->assertJsonPath('data.*.revenue', ['10.00', '0.00']);
    }

    // Queries

    public function test_uses_two_aggregate_queries_regardless_of_data_or_buckets(): void
    {
        $this->sale('10.00', '2026-09-10 15:00:00');
        $few = $this->transactionQueries(self::SEPTEMBER);

        foreach (range(1, 9) as $day) {
            $this->sale('10.00', "2026-09-0{$day} 15:00:00");
            $this->sale('10.00', "2026-08-1{$day} 15:00:00");
        }
        $many = $this->transactionQueries('from=2025-10-01&to=2026-09-30');

        $this->assertCount(2, $few);
        $this->assertCount(2, $many);
        $this->assertCount(1, array_filter($many, fn (string $sql) => str_contains($sql, 'group by')));
    }

    private function revenue(string $query = ''): TestResponse
    {
        return $this->actingInOrganization($this->owner, $this->organization)
            ->getJson('/api/v1/analytics/revenue'.($query === '' ? '' : "?{$query}"));
    }

    private function sale(string $amount, string $occurredAt, TransactionStatus $status = TransactionStatus::Paid): void
    {
        $this->createTransaction($this->customer, [[$this->product, 1, $amount]], $status, $occurredAt);
    }

    private function sumRevenue(TestResponse $response): string
    {
        return Money::fromCents(array_sum(array_map(Money::toCents(...), $response->json('data.*.revenue'))));
    }

    /**
     * SQL of the queries that read transactions during one request.
     *
     * @return list<string>
     */
    private function transactionQueries(string $query): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->revenue($query)->assertOk();

        $queries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql) => str_contains($sql, 'from "transactions"'))
            ->values()
            ->all();

        DB::disableQueryLog();

        return $queries;
    }
}
