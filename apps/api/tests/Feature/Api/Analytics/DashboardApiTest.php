<?php

namespace Tests\Feature\Api\Analytics;

use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

class DashboardApiTest extends TestCase
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

    public function test_returns_the_four_kpis_with_period_meta(): void
    {
        $this->sale('100.00', '2026-09-10 15:00:00');
        $this->sale('80.00', '2026-08-20 15:00:00');

        $response = $this->dashboard(self::SEPTEMBER)
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'revenue' => ['value' => '100.00', 'previous' => '80.00', 'change' => 25.0],
                    'orders' => ['value' => 1, 'previous' => 1, 'change' => 0.0],
                    'average_order_value' => ['value' => '100.00', 'previous' => '80.00', 'change' => 25.0],
                    'customers' => ['value' => 1, 'previous' => 1, 'change' => 0.0],
                ],
                'meta' => [
                    'period' => ['from' => '2026-09-01', 'to' => '2026-09-30', 'days' => 30],
                    'previous_period' => ['from' => '2026-08-02', 'to' => '2026-08-31', 'days' => 30],
                    'timezone' => 'America/Sao_Paulo',
                    'currency' => 'BRL',
                ],
            ]);

        $this->assertStringContainsString('"change":25.0', $response->getContent());
        $this->assertStringContainsString('"change":0.0', $response->getContent());
        $this->assertStringNotContainsString('organization_id', $response->getContent());
    }

    // Revenue, orders and the paid rule

    public function test_revenue_and_orders_count_only_paid_transactions(): void
    {
        $other = Customer::factory()->for($this->organization)->create();

        $this->sale('100.00', '2026-09-10 15:00:00');
        $this->sale('50.50', '2026-09-11 15:00:00', customer: $other);
        $this->sale('70.00', '2026-09-12 15:00:00', TransactionStatus::Pending);
        $this->sale('30.00', '2026-09-13 15:00:00', TransactionStatus::Refunded);
        $this->sale('20.00', '2026-09-14 15:00:00', TransactionStatus::Canceled);

        $this->dashboard(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.revenue.value', '150.50')
            ->assertJsonPath('data.orders.value', 2)
            ->assertJsonPath('data.average_order_value.value', '75.25')
            ->assertJsonPath('data.customers.value', 2);
    }

    public function test_period_boundaries_follow_sao_paulo_midnight(): void
    {
        // Sep 1st starts at 03:00 UTC; the previous period (Aug 2nd–31st) starts on 2026-08-02 03:00 UTC.
        $this->sale('160.00', '2026-08-02 02:59:59'); // Aug 1st 23:59:59 local: outside both periods
        $this->sale('320.00', '2026-08-02 03:00:00'); // Aug 2nd 00:00 local: previous
        $this->sale('10.00', '2026-09-01 02:59:59');  // Aug 31st 23:59:59 local: previous
        $this->sale('20.00', '2026-09-01 03:00:00');  // Sep 1st 00:00 local: current
        $this->sale('40.00', '2026-10-01 02:59:59');  // Sep 30th 23:59:59 local: current
        $this->sale('80.00', '2026-10-01 03:00:00');  // Oct 1st 00:00 local: outside

        $this->dashboard(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.revenue.value', '60.00')
            ->assertJsonPath('data.revenue.previous', '330.00')
            ->assertJsonPath('data.orders.value', 2)
            ->assertJsonPath('data.orders.previous', 2);
    }

    public function test_period_boundaries_follow_lisbon_daylight_saving_time(): void
    {
        $this->organization->update(['timezone' => 'Europe/Lisbon']);

        // Mar 29th 2026 is a 23h day in Lisbon: 00:00 UTC → 23:00 UTC. The previous day is Mar 28th.
        $this->sale('1.00', '2026-03-27 23:59:59'); // Mar 27th: outside
        $this->sale('2.00', '2026-03-28 23:59:59'); // Mar 28th: previous
        $this->sale('4.00', '2026-03-29 00:00:00'); // Mar 29th 00:00 WET: current
        $this->sale('8.00', '2026-03-29 22:59:59'); // Mar 29th 23:59:59 WEST: current
        $this->sale('16.00', '2026-03-29 23:00:00'); // Mar 30th 00:00 WEST: outside

        $this->dashboard('from=2026-03-29&to=2026-03-29')
            ->assertOk()
            ->assertJsonPath('data.revenue.value', '12.00')
            ->assertJsonPath('data.revenue.previous', '2.00')
            ->assertJsonPath('meta.timezone', 'Europe/Lisbon')
            ->assertJsonPath('meta.previous_period', ['from' => '2026-03-28', 'to' => '2026-03-28', 'days' => 1]);
    }

    public function test_sale_before_the_previous_period_is_ignored(): void
    {
        $this->sale('999.00', '2026-08-01 15:00:00');

        $this->dashboard(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.revenue.previous', '0.00')
            ->assertJsonPath('data.orders.previous', 0);
    }

    // Average order value

    public function test_average_order_value_uses_the_same_paid_orders_and_rounds_half_up(): void
    {
        $this->sale('10.00', '2026-09-10 15:00:00');
        $this->sale('10.00', '2026-09-11 15:00:00');
        $this->sale('10.01', '2026-09-12 15:00:00');
        $this->sale('500.00', '2026-09-12 16:00:00', TransactionStatus::Refunded);

        // 30.01 / 3 = 10.0033…
        $this->dashboard(self::SEPTEMBER)->assertJsonPath('data.average_order_value.value', '10.00');

        // 20.01 / 2 = 10.005 → 10.01
        $this->dashboard('from=2026-09-11&to=2026-09-12')->assertJsonPath('data.average_order_value.value', '10.01');
    }

    public function test_average_order_value_is_null_without_orders(): void
    {
        $this->sale('80.00', '2026-08-20 15:00:00');

        $this->dashboard(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.revenue', ['value' => '0.00', 'previous' => '80.00', 'change' => -100.0])
            ->assertJsonPath('data.orders', ['value' => 0, 'previous' => 1, 'change' => -100.0])
            ->assertJsonPath('data.average_order_value', ['value' => null, 'previous' => '80.00', 'change' => null])
            ->assertJsonPath('data.customers', ['value' => 0, 'previous' => 1, 'change' => -100.0]);
    }

    // Customers

    public function test_customers_counts_distinct_buyers_with_paid_transactions(): void
    {
        $refundedOnly = Customer::factory()->for($this->organization)->create();
        $deletedLater = Customer::factory()->for($this->organization)->create();

        $this->sale('10.00', '2026-09-10 15:00:00');
        $this->sale('10.00', '2026-09-11 15:00:00');
        $this->sale('10.00', '2026-09-12 15:00:00');
        $this->sale('10.00', '2026-09-12 15:00:00', TransactionStatus::Refunded, $refundedOnly);
        $this->sale('10.00', '2026-09-13 15:00:00', customer: $deletedLater);
        $deletedLater->delete();

        $this->dashboard(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.customers.value', 2)
            ->assertJsonPath('data.orders.value', 4);
    }

    public function test_customers_respect_the_period(): void
    {
        $this->sale('10.00', '2026-08-20 15:00:00');
        $this->sale('10.00', '2026-10-05 15:00:00', customer: Customer::factory()->for($this->organization)->create());

        $this->dashboard(self::SEPTEMBER)
            ->assertJsonPath('data.customers', ['value' => 0, 'previous' => 1, 'change' => -100.0]);
    }

    // Comparison

    public function test_change_is_the_percent_variation_against_the_previous_period(): void
    {
        $this->sale('100.00', '2026-09-10 15:00:00');
        $this->sale('50.00', '2026-09-11 15:00:00');
        $this->sale('20.00', '2026-09-12 15:00:00');
        $this->sale('60.00', '2026-08-10 15:00:00');
        $this->sale('60.00', '2026-08-11 15:00:00');

        $this->dashboard(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.revenue', ['value' => '170.00', 'previous' => '120.00', 'change' => 41.7])
            ->assertJsonPath('data.orders', ['value' => 3, 'previous' => 2, 'change' => 50.0])
            ->assertJsonPath('data.average_order_value', ['value' => '56.67', 'previous' => '60.00', 'change' => -5.6]);
    }

    public function test_change_is_null_when_the_previous_period_is_empty(): void
    {
        $this->sale('100.00', '2026-09-10 15:00:00');

        $this->dashboard(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.revenue', ['value' => '100.00', 'previous' => '0.00', 'change' => null])
            ->assertJsonPath('data.orders', ['value' => 1, 'previous' => 0, 'change' => null])
            ->assertJsonPath('data.average_order_value', ['value' => '100.00', 'previous' => null, 'change' => null]);
    }

    public function test_empty_periods_report_zero_and_no_change(): void
    {
        $this->dashboard(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data', [
                'revenue' => ['value' => '0.00', 'previous' => '0.00', 'change' => 0.0],
                'orders' => ['value' => 0, 'previous' => 0, 'change' => 0.0],
                'average_order_value' => ['value' => null, 'previous' => null, 'change' => null],
                'customers' => ['value' => 0, 'previous' => 0, 'change' => 0.0],
            ]);
    }

    // Period parameters

    public function test_defaults_to_the_last_30_days_in_the_organization_timezone(): void
    {
        // 01:30 UTC on Oct 1st is still Sep 30th in São Paulo.
        Carbon::setTestNow('2026-10-01 01:30:00');
        $this->sale('25.00', '2026-10-01 01:00:00');

        $this->dashboard()
            ->assertOk()
            ->assertJsonPath('meta.period', ['from' => '2026-09-01', 'to' => '2026-09-30', 'days' => 30])
            ->assertJsonPath('meta.previous_period', ['from' => '2026-08-02', 'to' => '2026-08-31', 'days' => 30])
            ->assertJsonPath('data.revenue.value', '25.00');
    }

    public function test_rejects_invalid_periods(): void
    {
        $this->dashboard('from=2026-09-10&to=2026-09-01')->assertUnprocessable()->assertJsonValidationErrors(['to']);
        $this->dashboard('from=2026-09-01')->assertUnprocessable()->assertJsonValidationErrors(['to']);
        $this->dashboard('from=2025-01-01&to=2026-01-02')->assertUnprocessable()->assertJsonValidationErrors(['to']);
        $this->dashboard('from=01/09/2026&to=2026-09-30')->assertUnprocessable()->assertJsonValidationErrors(['from']);
    }

    // Tenant isolation and access

    public function test_other_organization_transactions_are_never_included(): void
    {
        $foreign = Organization::factory()->create();
        $foreignOwner = $this->memberOf($foreign);
        $foreignCustomer = Customer::factory()->for($foreign)->create();
        $foreignProduct = Product::factory()->for($foreign)->create();
        $this->createTransaction($foreignCustomer, [[$foreignProduct, 1, '900.00']], occurredAt: '2026-09-10 15:00:00');
        $this->createTransaction($foreignCustomer, [[$foreignProduct, 1, '700.00']], occurredAt: '2026-08-10 15:00:00');

        $this->sale('100.00', '2026-09-10 15:00:00');

        $this->dashboard(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.revenue', ['value' => '100.00', 'previous' => '0.00', 'change' => null])
            ->assertJsonPath('data.orders.value', 1)
            ->assertJsonPath('data.customers.value', 1);

        $this->actingInOrganization($foreignOwner, $foreign)
            ->getJson('/api/v1/dashboard?'.self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.revenue.value', '900.00')
            ->assertJsonPath('data.revenue.previous', '700.00');
    }

    public function test_organization_id_is_rejected_and_timezone_parameter_is_ignored(): void
    {
        $foreign = Organization::factory()->create();

        $this->dashboard(self::SEPTEMBER.'&organization_id='.$foreign->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['organization_id']);

        // 23:30 local on Aug 31st (02:30 UTC on Sep 1st) stays in the previous period.
        $this->sale('10.00', '2026-09-01 02:30:00');

        $this->dashboard(self::SEPTEMBER.'&timezone=UTC')
            ->assertOk()
            ->assertJsonPath('meta.timezone', 'America/Sao_Paulo')
            ->assertJsonPath('data.revenue.value', '0.00')
            ->assertJsonPath('data.revenue.previous', '10.00');
    }

    public function test_organization_header_without_membership_is_forbidden(): void
    {
        $this->actingInOrganization($this->owner, Organization::factory()->create())
            ->getJson('/api/v1/dashboard')
            ->assertForbidden();
    }

    public function test_guest_receives_401(): void
    {
        $this->withHeader('X-Organization-Id', $this->organization->id)
            ->getJson('/api/v1/dashboard')
            ->assertUnauthorized();
    }

    public function test_member_role_can_read_the_dashboard(): void
    {
        $member = $this->memberOf($this->organization, Organization::ROLE_MEMBER);
        $this->sale('10.00', '2026-09-10 15:00:00');

        $this->actingInOrganization($member, $this->organization)
            ->getJson('/api/v1/dashboard?'.self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.orders.value', 1);
    }

    // Performance

    public function test_kpis_come_from_a_single_aggregate_query(): void
    {
        $this->sale('10.00', '2026-09-10 15:00:00');
        $few = $this->transactionQueries();

        foreach (range(1, 8) as $day) {
            $this->sale('10.00', "2026-09-1{$day} 15:00:00", customer: Customer::factory()->for($this->organization)->create());
            $this->sale('10.00', "2026-08-1{$day} 15:00:00");
        }
        $many = $this->transactionQueries();

        $this->assertCount(1, $few);
        $this->assertCount(1, $many);
        $this->assertStringContainsString('count(distinct', $many[0]);
    }

    private function dashboard(string $query = ''): TestResponse
    {
        return $this->actingInOrganization($this->owner, $this->organization)
            ->getJson('/api/v1/dashboard'.($query === '' ? '' : "?{$query}"));
    }

    private function sale(
        string $amount,
        string $occurredAt,
        TransactionStatus $status = TransactionStatus::Paid,
        ?Customer $customer = null,
    ): void {
        $this->createTransaction($customer ?? $this->customer, [[$this->product, 1, $amount]], $status, $occurredAt);
    }

    /**
     * SQL of the queries that read transactions during one dashboard request.
     *
     * @return list<string>
     */
    private function transactionQueries(): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->dashboard(self::SEPTEMBER)->assertOk();

        $queries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql) => str_contains($sql, 'from "transactions"'))
            ->values()
            ->all();

        DB::disableQueryLog();

        return $queries;
    }
}
