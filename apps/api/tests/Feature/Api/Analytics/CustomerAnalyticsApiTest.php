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

class CustomerAnalyticsApiTest extends TestCase
{
    use InteractsWithOrganizationApi, RefreshDatabase;

    private const SEPTEMBER = 'from=2026-09-01&to=2026-09-30';

    private const LONG_AGO = '2026-01-01 12:00:00';

    private const ZERO = ['value' => 0, 'previous' => 0, 'change' => 0.0];

    private Organization $organization;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->owner = $this->memberOf($this->organization);
        $this->product = Product::factory()->for($this->organization)->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // Contract

    public function test_returns_ranking_with_comparisons_summary_and_meta(): void
    {
        $maria = $this->customer('Maria');
        $joao = $this->customer('Joao');
        $carla = $this->customer('Carla');
        $this->customer('Ana', '2026-09-15 12:00:00');
        $this->customer('Bia', '2026-09-16 12:00:00');
        $this->deleted($this->customer('Old'), '2026-08-20 12:00:00');

        $this->buy($maria, '1000.00', '2026-08-10 15:00:00');
        $this->buy($maria, '600.00', '2026-09-05 15:00:00');
        $this->buy($maria, '650.00', '2026-09-06 15:00:00');
        $this->buy($joao, '480.00', '2026-09-10 15:00:00');
        $this->buy($carla, '100.00', '2026-08-15 15:00:00');
        $this->deleted($joao, '2026-09-20 12:00:00');

        $response = $this->customers(self::SEPTEMBER)
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    [
                        'rank' => 1,
                        'customer' => ['id' => $maria->id, 'name' => 'Maria', 'email' => $maria->email, 'is_deleted' => false],
                        'revenue' => ['value' => '1250.00', 'previous' => '1000.00', 'change' => 25.0],
                        'orders' => ['value' => 2, 'previous' => 1, 'change' => 100.0],
                    ],
                    [
                        'rank' => 2,
                        'customer' => ['id' => $joao->id, 'name' => 'Joao', 'email' => $joao->email, 'is_deleted' => true],
                        'revenue' => ['value' => '480.00', 'previous' => '0.00', 'change' => null],
                        'orders' => ['value' => 1, 'previous' => 0, 'change' => null],
                    ],
                ],
                'summary' => [
                    'total_customers' => ['value' => 4, 'previous' => 3, 'change' => 33.3],
                    'active_customers' => ['value' => 2, 'previous' => 2, 'change' => 0.0],
                    'new_customers' => ['value' => 1, 'previous' => 2, 'change' => -50.0],
                    'returning_customers' => ['value' => 1, 'previous' => 0, 'change' => null],
                ],
                'meta' => [
                    'period' => ['from' => '2026-09-01', 'to' => '2026-09-30', 'days' => 30],
                    'previous_period' => ['from' => '2026-08-02', 'to' => '2026-08-31', 'days' => 30],
                    'timezone' => 'America/Sao_Paulo',
                    'currency' => 'BRL',
                    'sort' => 'revenue',
                    'limit' => 10,
                ],
            ]);

        $this->assertStringContainsString('"change":25.0', $response->getContent());
        $this->assertStringContainsString('"change":0.0', $response->getContent());
        $this->assertStringNotContainsString('organization_id', $response->getContent());
    }

    // Empty and zero

    public function test_organization_without_customers_returns_zeros(): void
    {
        $this->customers(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('summary', [
                'total_customers' => self::ZERO,
                'active_customers' => self::ZERO,
                'new_customers' => self::ZERO,
                'returning_customers' => self::ZERO,
            ]);
    }

    public function test_customers_without_sales_only_count_in_the_total(): void
    {
        foreach (['Ana', 'Bia', 'Carla'] as $name) {
            $this->customer($name);
        }

        $this->customers(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('summary', [
                'total_customers' => ['value' => 3, 'previous' => 3, 'change' => 0.0],
                'active_customers' => self::ZERO,
                'new_customers' => self::ZERO,
                'returning_customers' => self::ZERO,
            ]);
    }

    // Previous period

    public function test_customers_active_only_in_the_previous_period_are_not_ranked(): void
    {
        $this->buy($this->customer('Ana'), '80.00', '2026-08-20 15:00:00');

        $this->customers(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('summary.active_customers', ['value' => 0, 'previous' => 1, 'change' => -100.0])
            ->assertJsonPath('summary.new_customers', ['value' => 0, 'previous' => 1, 'change' => -100.0])
            ->assertJsonPath('summary.returning_customers', self::ZERO);
    }

    public function test_sales_outside_both_periods_are_ignored(): void
    {
        $ana = $this->customer('Ana');
        $this->buy($ana, '10.00', '2026-09-10 15:00:00');
        $this->buy($ana, '70.00', '2026-10-01 15:00:00');

        $this->customers(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.0.revenue', ['value' => '10.00', 'previous' => '0.00', 'change' => null])
            ->assertJsonPath('data.0.orders', ['value' => 1, 'previous' => 0, 'change' => null])
            ->assertJsonPath('summary.new_customers.value', 1);
    }

    // Paid rule

    public function test_only_paid_transactions_create_activity_and_first_purchases(): void
    {
        $refundedBefore = $this->customer('Refunded before');
        $this->buy($refundedBefore, '50.00', '2026-07-10 15:00:00', TransactionStatus::Refunded);
        $this->buy($refundedBefore, '20.00', '2026-09-10 15:00:00');

        $unpaid = $this->customer('Unpaid');
        $this->buy($unpaid, '99.00', '2026-09-10 15:00:00', TransactionStatus::Pending);
        $this->buy($unpaid, '99.00', '2026-09-11 15:00:00', TransactionStatus::Canceled);
        $this->buy($unpaid, '99.00', '2026-09-12 15:00:00', TransactionStatus::Refunded);

        $this->customers(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.*.customer.name', ['Refunded before'])
            ->assertJsonPath('data.0.revenue.value', '20.00')
            ->assertJsonPath('data.0.orders.value', 1)
            ->assertJsonPath('summary.active_customers.value', 1)
            ->assertJsonPath('summary.new_customers.value', 1)
            ->assertJsonPath('summary.returning_customers.value', 0)
            ->assertJsonPath('summary.total_customers.value', 2);
    }

    // New and returning

    public function test_new_and_returning_partition_active_customers(): void
    {
        $this->buy($this->customer('First in period'), '10.00', '2026-09-10 15:00:00');

        $loyal = $this->customer('Loyal');
        $this->buy($loyal, '10.00', '2026-05-10 15:00:00');
        $this->buy($loyal, '10.00', '2026-09-10 15:00:00');

        $back = $this->customer('Back');
        $this->buy($back, '10.00', '2026-08-10 15:00:00');
        $this->buy($back, '10.00', '2026-09-12 15:00:00');

        $lapsed = $this->customer('Lapsed');
        $this->buy($lapsed, '10.00', '2026-06-10 15:00:00');
        $this->buy($lapsed, '10.00', '2026-08-12 15:00:00');

        $response = $this->customers(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('summary.active_customers', ['value' => 3, 'previous' => 2, 'change' => 50.0])
            ->assertJsonPath('summary.new_customers', ['value' => 1, 'previous' => 1, 'change' => 0.0])
            ->assertJsonPath('summary.returning_customers', ['value' => 2, 'previous' => 1, 'change' => 100.0]);

        foreach (['value', 'previous'] as $key) {
            $this->assertSame(
                $response->json("summary.active_customers.{$key}"),
                $response->json("summary.new_customers.{$key}") + $response->json("summary.returning_customers.{$key}"),
                $key,
            );
        }
    }

    public function test_several_purchases_in_the_first_period_still_count_as_new(): void
    {
        $ana = $this->customer('Ana');
        $this->buy($ana, '10.00', '2026-09-02 15:00:00');
        $this->buy($ana, '10.00', '2026-09-20 15:00:00');

        $this->customers(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('summary.new_customers.value', 1)
            ->assertJsonPath('summary.returning_customers.value', 0)
            ->assertJsonPath('data.0.orders.value', 2);
    }

    // Total customers

    public function test_total_counts_customers_registered_by_the_end_of_each_period(): void
    {
        $this->customer('Long ago');
        $this->customer('In previous', '2026-08-15 12:00:00');
        $this->customer('In period', '2026-09-15 12:00:00');
        $this->customer('After period', '2026-10-05 12:00:00');

        $this->customers(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('summary.total_customers', ['value' => 3, 'previous' => 2, 'change' => 50.0]);
    }

    // Soft deletes

    public function test_soft_deleted_customers_keep_their_history_but_leave_the_total(): void
    {
        $gone = $this->customer('Gone');
        $this->buy($gone, '30.00', '2026-08-10 15:00:00');
        $this->buy($gone, '40.00', '2026-09-10 15:00:00');
        $this->deleted($gone, '2026-09-15 12:00:00');

        $this->customers(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.0.customer', ['id' => $gone->id, 'name' => 'Gone', 'email' => $gone->email, 'is_deleted' => true])
            ->assertJsonPath('data.0.revenue', ['value' => '40.00', 'previous' => '30.00', 'change' => 33.3])
            ->assertJsonPath('summary.active_customers', ['value' => 1, 'previous' => 1, 'change' => 0.0])
            ->assertJsonPath('summary.returning_customers.value', 1)
            ->assertJsonPath('summary.total_customers', ['value' => 0, 'previous' => 1, 'change' => -100.0]);
    }

    public function test_deletion_before_or_after_the_period_decides_the_total(): void
    {
        $this->deleted($this->customer('Deleted before'), '2026-07-10 12:00:00');
        $this->deleted($this->customer('Deleted after'), '2026-10-05 12:00:00');

        $this->customers(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('summary.total_customers', ['value' => 1, 'previous' => 1, 'change' => 0.0]);
    }

    public function test_purchases_of_a_customer_deleted_before_the_period_still_count(): void
    {
        $gone = $this->customer('Gone');
        $this->buy($gone, '25.00', '2026-09-10 15:00:00');
        $this->deleted($gone, '2026-07-01 12:00:00');

        $this->customers(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.0.customer.is_deleted', true)
            ->assertJsonPath('summary.active_customers.value', 1)
            ->assertJsonPath('summary.new_customers.value', 1)
            ->assertJsonPath('summary.total_customers.value', 0);
    }

    // Ranking

    public function test_sort_ranks_by_revenue_or_orders(): void
    {
        $big = $this->customer('Big spender');
        $frequent = $this->customer('Frequent');
        $this->buy($big, '500.00', '2026-09-10 15:00:00');
        foreach (range(10, 14) as $day) {
            $this->buy($frequent, '10.00', "2026-09-{$day} 15:00:00");
        }

        $this->customers(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.*.customer.name', ['Big spender', 'Frequent'])
            ->assertJsonPath('data.*.rank', [1, 2]);

        $this->customers(self::SEPTEMBER.'&sort=orders')
            ->assertOk()
            ->assertJsonPath('data.*.customer.name', ['Frequent', 'Big spender'])
            ->assertJsonPath('meta.sort', 'orders');
    }

    public function test_revenue_ties_are_broken_by_orders_then_name_then_id(): void
    {
        $this->buy($this->customer('Banana'), '100.00', '2026-09-10 15:00:00');
        $this->buy($this->customer('Apple'), '100.00', '2026-09-10 15:00:00');
        $twice = $this->customer('Zed');
        $this->buy($twice, '50.00', '2026-09-10 15:00:00');
        $this->buy($twice, '50.00', '2026-09-11 15:00:00');
        $first = $this->customer('Same');
        $second = $this->customer('Same');
        $this->buy($first, '10.00', '2026-09-10 15:00:00');
        $this->buy($second, '10.00', '2026-09-10 15:00:00');

        $ids = [$first->id, $second->id];
        sort($ids);

        $response = $this->customers(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.*.customer.name', ['Zed', 'Apple', 'Banana', 'Same', 'Same']);

        $this->assertSame($ids, [$response->json('data.3.customer.id'), $response->json('data.4.customer.id')]);
    }

    public function test_order_ties_are_broken_by_revenue_then_name(): void
    {
        $this->buy($this->customer('Low'), '10.00', '2026-09-10 15:00:00');
        $this->buy($this->customer('High'), '90.00', '2026-09-10 15:00:00');
        $this->buy($this->customer('Beta'), '10.00', '2026-09-10 15:00:00');

        $this->customers(self::SEPTEMBER.'&sort=orders')
            ->assertOk()
            ->assertJsonPath('data.*.customer.name', ['High', 'Beta', 'Low']);
    }

    public function test_limit_defaults_to_ten_and_never_affects_the_summary(): void
    {
        foreach (range(1, 12) as $n) {
            $this->buy($this->customer(sprintf('Customer %02d', $n)), "{$n}.00", '2026-09-10 15:00:00');
        }

        $this->customers(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('data.0.customer.name', 'Customer 12')
            ->assertJsonPath('meta.limit', 10);

        $this->customers(self::SEPTEMBER.'&limit=2')
            ->assertOk()
            ->assertJsonPath('data.*.customer.name', ['Customer 12', 'Customer 11'])
            ->assertJsonPath('meta.limit', 2)
            ->assertJsonPath('summary.active_customers.value', 12)
            ->assertJsonPath('summary.new_customers.value', 12)
            ->assertJsonPath('summary.total_customers.value', 12);

        $this->customers(self::SEPTEMBER.'&limit=50')->assertOk()->assertJsonCount(12, 'data');
    }

    // Defaults

    public function test_defaults_to_the_last_30_days_in_the_organization_timezone(): void
    {
        // 01:30 UTC on Oct 1st is still Sep 30th in São Paulo.
        Carbon::setTestNow('2026-10-01 01:30:00');
        $this->buy($this->customer('Ana'), '25.00', '2026-10-01 01:00:00');

        $this->customers()
            ->assertOk()
            ->assertJsonPath('meta.period', ['from' => '2026-09-01', 'to' => '2026-09-30', 'days' => 30])
            ->assertJsonPath('meta.sort', 'revenue')
            ->assertJsonPath('meta.limit', 10)
            ->assertJsonPath('data.0.revenue.value', '25.00')
            ->assertJsonPath('summary.active_customers.value', 1);
    }

    // Timezone

    public function test_sao_paulo_midnight_splits_current_and_previous_activity(): void
    {
        $this->buy($this->customer('Before'), '10.00', '2026-09-01 02:59:59'); // Aug 31st 23:59:59 local
        $this->buy($this->customer('After'), '20.00', '2026-09-01 03:00:00'); // Sep 1st 00:00 local

        $this->customers('from=2026-09-01&to=2026-09-01')
            ->assertOk()
            ->assertJsonPath('data.*.customer.name', ['After'])
            ->assertJsonPath('summary.active_customers', ['value' => 1, 'previous' => 1, 'change' => 0.0]);
    }

    public function test_organization_timezone_decides_whether_a_customer_is_new_or_returning(): void
    {
        $ana = $this->customer('Ana');
        $this->buy($ana, '10.00', '2026-09-01 02:30:00'); // Aug 31st 23:30 in São Paulo, Sep 1st 03:30 in Lisbon
        $this->buy($ana, '10.00', '2026-09-10 15:00:00');

        $this->customers(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('summary.new_customers.value', 0)
            ->assertJsonPath('summary.returning_customers.value', 1)
            ->assertJsonPath('data.0.orders.value', 1);

        $this->organization->update(['timezone' => 'Europe/Lisbon']);

        $this->customers(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('meta.timezone', 'Europe/Lisbon')
            ->assertJsonPath('summary.new_customers.value', 1)
            ->assertJsonPath('summary.returning_customers.value', 0)
            ->assertJsonPath('data.0.orders.value', 2);
    }

    public function test_organization_timezone_decides_the_registration_boundary(): void
    {
        $this->customer('Registered late', '2026-09-30 23:30:00'); // Sep 30th 20:30 in São Paulo, Oct 1st 00:30 in Lisbon

        $this->customers(self::SEPTEMBER)->assertOk()->assertJsonPath('summary.total_customers.value', 1);

        $this->organization->update(['timezone' => 'Europe/Lisbon']);

        $this->customers(self::SEPTEMBER)->assertOk()->assertJsonPath('summary.total_customers.value', 0);
    }

    public function test_organization_timezone_decides_the_deletion_boundary(): void
    {
        $this->deleted($this->customer('Deleted late'), '2026-09-30 23:30:00'); // Sep 30th 20:30 in São Paulo, Oct 1st 00:30 in Lisbon

        $this->customers(self::SEPTEMBER)->assertOk()->assertJsonPath('summary.total_customers.value', 0);

        $this->organization->update(['timezone' => 'Europe/Lisbon']);

        $this->customers(self::SEPTEMBER)->assertOk()->assertJsonPath('summary.total_customers.value', 1);
    }

    public function test_lisbon_daylight_saving_day_has_23_hours(): void
    {
        $this->organization->update(['timezone' => 'Europe/Lisbon']);

        // Lisbon switches from UTC+0 to UTC+1 on 2026-03-29 at 01:00 UTC; the local day ends at 23:00 UTC.
        $this->buy($this->customer('Last second'), '10.00', '2026-03-29 22:59:59'); // Mar 29th 23:59:59 WEST
        $this->buy($this->customer('Next day'), '10.00', '2026-03-29 23:00:00'); // Mar 30th 00:00 WEST
        $this->customer('Registered in time', '2026-03-29 22:30:00');
        $this->customer('Registered too late', '2026-03-29 23:00:00');

        $this->customers('from=2026-03-29&to=2026-03-29')
            ->assertOk()
            ->assertJsonPath('meta.period', ['from' => '2026-03-29', 'to' => '2026-03-29', 'days' => 1])
            ->assertJsonPath('data.*.customer.name', ['Last second'])
            ->assertJsonPath('summary.new_customers.value', 1)
            ->assertJsonPath('summary.total_customers.value', 3);
    }

    // Tenant isolation and access

    public function test_other_organization_customers_and_sales_are_never_included(): void
    {
        $foreign = Organization::factory()->create();
        $foreignOwner = $this->memberOf($foreign);
        $foreignProduct = Product::factory()->for($foreign)->create();
        $foreignCustomer = Customer::factory()->for($foreign)->create(['name' => 'Foreign', 'created_at' => self::LONG_AGO]);
        $this->deleted(Customer::factory()->for($foreign)->create(['created_at' => self::LONG_AGO]), '2026-09-10 12:00:00');
        $this->createTransaction($foreignCustomer, [[$foreignProduct, 1, '900.00']], occurredAt: '2026-09-10 15:00:00');
        $this->createTransaction($foreignCustomer, [[$foreignProduct, 1, '100.00']], occurredAt: '2026-08-10 15:00:00');

        $this->buy($this->customer('Local'), '10.00', '2026-09-10 15:00:00');

        $response = $this->customers(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.*.customer.name', ['Local'])
            ->assertJsonPath('summary', [
                'total_customers' => ['value' => 1, 'previous' => 1, 'change' => 0.0],
                'active_customers' => ['value' => 1, 'previous' => 0, 'change' => null],
                'new_customers' => ['value' => 1, 'previous' => 0, 'change' => null],
                'returning_customers' => self::ZERO,
            ]);

        $this->assertStringNotContainsString($foreignCustomer->id, $response->getContent());

        $this->actingInOrganization($foreignOwner, $foreign)
            ->getJson('/api/v1/analytics/customers?'.self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.*.customer.name', ['Foreign'])
            ->assertJsonPath('data.0.revenue', ['value' => '900.00', 'previous' => '100.00', 'change' => 800.0])
            ->assertJsonPath('summary.total_customers', ['value' => 1, 'previous' => 2, 'change' => -50.0])
            ->assertJsonPath('summary.returning_customers.value', 1);
    }

    public function test_organization_header_without_membership_is_forbidden(): void
    {
        $this->actingInOrganization($this->owner, Organization::factory()->create())
            ->getJson('/api/v1/analytics/customers')
            ->assertForbidden();
    }

    public function test_guest_receives_401(): void
    {
        $this->withHeader('X-Organization-Id', $this->organization->id)
            ->getJson('/api/v1/analytics/customers')
            ->assertUnauthorized();
    }

    public function test_owner_and_member_can_read_customer_analytics(): void
    {
        $member = $this->memberOf($this->organization, Organization::ROLE_MEMBER);
        $this->buy($this->customer('Ana'), '10.00', '2026-09-10 15:00:00');

        foreach ([$this->owner, $member] as $user) {
            $this->actingInOrganization($user, $this->organization)
                ->getJson('/api/v1/analytics/customers?'.self::SEPTEMBER)
                ->assertOk()
                ->assertJsonPath('data.0.revenue.value', '10.00');
        }
    }

    // Validation

    public function test_rejects_invalid_sort_limit_and_periods(): void
    {
        $this->customers(self::SEPTEMBER.'&sort=total')->assertUnprocessable()->assertJsonValidationErrors(['sort']);
        $this->customers(self::SEPTEMBER.'&limit=0')->assertUnprocessable()->assertJsonValidationErrors(['limit']);
        $this->customers(self::SEPTEMBER.'&limit=51')->assertUnprocessable()->assertJsonValidationErrors(['limit']);
        $this->customers(self::SEPTEMBER.'&limit=abc')->assertUnprocessable()->assertJsonValidationErrors(['limit']);
        $this->customers('from=01/09/2026&to=2026-02-30')->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
        $this->customers('from=2026-09-01')->assertUnprocessable()->assertJsonValidationErrors(['to']);
        $this->customers('from=2026-09-10&to=2026-09-01')->assertUnprocessable()->assertJsonValidationErrors(['to']);
        $this->customers('from=2025-01-01&to=2026-01-02')->assertUnprocessable()->assertJsonValidationErrors(['to']);
    }

    public function test_organization_id_is_rejected_and_timezone_parameter_is_ignored(): void
    {
        $this->customers(self::SEPTEMBER.'&organization_id='.Organization::factory()->create()->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['organization_id']);

        $this->buy($this->customer('Ana'), '10.00', '2026-09-01 02:30:00'); // Aug 31st 23:30 local

        $this->customers('from=2026-09-01&to=2026-09-01&timezone=UTC')
            ->assertOk()
            ->assertJsonPath('meta.timezone', 'America/Sao_Paulo')
            ->assertJsonPath('data', [])
            ->assertJsonPath('summary.active_customers.previous', 1);
    }

    // Queries

    public function test_uses_four_queries_regardless_of_customers(): void
    {
        $this->buy($this->customer('Ana'), '10.00', '2026-09-10 15:00:00');
        $few = $this->queries(self::SEPTEMBER);

        foreach (range(1, 25) as $n) {
            $customer = $this->customer("Customer {$n}");
            $this->buy($customer, '10.00', '2026-09-1'.($n % 10).' 15:00:00');
            $this->buy($customer, '10.00', '2026-08-1'.($n % 10).' 15:00:00');
        }
        $many = $this->queries(self::SEPTEMBER.'&limit=50&sort=orders');

        foreach (['few' => $few, 'many' => $many] as $label => $queries) {
            $this->assertCount(3, array_filter($queries, fn (string $sql) => str_contains($sql, 'from "transactions"')), $label);
            $this->assertCount(1, array_filter($queries, fn (string $sql) => str_contains($sql, 'from "customers"')), $label);
            $this->assertCount(2, array_filter($queries, fn (string $sql) => str_contains($sql, '"customers"')), $label);
        }
    }

    private function customers(string $query = ''): TestResponse
    {
        return $this->actingInOrganization($this->owner, $this->organization)
            ->getJson('/api/v1/analytics/customers'.($query === '' ? '' : "?{$query}"));
    }

    /**
     * Registration dates are explicit so the real clock never decides the customer base.
     */
    private function customer(string $name, string $createdAt = self::LONG_AGO): Customer
    {
        return Customer::factory()->for($this->organization)->create([
            'name' => $name,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function deleted(Customer $customer, string $deletedAt): void
    {
        $customer->forceFill(['deleted_at' => $deletedAt])->save();
    }

    private function buy(Customer $customer, string $amount, string $occurredAt, TransactionStatus $status = TransactionStatus::Paid): void
    {
        $this->createTransaction($customer, [[$this->product, 1, $amount]], $status, $occurredAt);
    }

    /**
     * SQL of every query run during one request.
     *
     * @return list<string>
     */
    private function queries(string $query): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->customers($query)->assertOk();

        $queries = collect(DB::getQueryLog())->pluck('query')->values()->all();

        DB::disableQueryLog();

        return $queries;
    }
}
