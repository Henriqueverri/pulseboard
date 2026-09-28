<?php

namespace Tests\Feature\Api\Analytics;

use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

class TransactionStatusAnalyticsApiTest extends TestCase
{
    use InteractsWithOrganizationApi, RefreshDatabase;

    private const SEPTEMBER = 'from=2026-09-01&to=2026-09-30';

    private const URI = '/api/v1/analytics/transactions';

    private const STATUSES = ['paid', 'refunded', 'pending', 'canceled'];

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

    public function test_returns_every_status_with_comparisons_and_meta(): void
    {
        $this->createTransaction($this->customer, [[$this->product, 2, '25.00'], [$this->product, 1, '50.00']], occurredAt: '2026-09-05 15:00:00');
        $this->transaction(TransactionStatus::Paid, '50.00', '2026-09-06 15:00:00');
        $this->transaction(TransactionStatus::Refunded, '30.00', '2026-09-07 15:00:00');
        $this->transaction(TransactionStatus::Pending, '20.00', '2026-09-08 15:00:00');
        $this->transaction(TransactionStatus::Paid, '100.00', '2026-08-10 15:00:00');
        $this->transaction(TransactionStatus::Canceled, '40.00', '2026-08-11 15:00:00');

        $response = $this->statuses(self::SEPTEMBER)
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    [
                        'status' => 'paid',
                        'orders' => ['value' => 2, 'previous' => 1, 'change' => 100.0],
                        'revenue' => ['value' => '150.00', 'previous' => '100.00', 'change' => 50.0],
                        'percentage' => ['value' => 50.0, 'previous' => 50.0, 'change' => 0.0],
                    ],
                    [
                        'status' => 'refunded',
                        'orders' => ['value' => 1, 'previous' => 0, 'change' => null],
                        'revenue' => ['value' => '30.00', 'previous' => '0.00', 'change' => null],
                        'percentage' => ['value' => 25.0, 'previous' => 0.0, 'change' => null],
                    ],
                    [
                        'status' => 'pending',
                        'orders' => ['value' => 1, 'previous' => 0, 'change' => null],
                        'revenue' => ['value' => '20.00', 'previous' => '0.00', 'change' => null],
                        'percentage' => ['value' => 25.0, 'previous' => 0.0, 'change' => null],
                    ],
                    [
                        'status' => 'canceled',
                        'orders' => ['value' => 0, 'previous' => 1, 'change' => -100.0],
                        'revenue' => ['value' => '0.00', 'previous' => '40.00', 'change' => -100.0],
                        'percentage' => ['value' => 0.0, 'previous' => 50.0, 'change' => -100.0],
                    ],
                ],
                'meta' => [
                    'period' => ['from' => '2026-09-01', 'to' => '2026-09-30', 'days' => 30],
                    'previous_period' => ['from' => '2026-08-02', 'to' => '2026-08-31', 'days' => 30],
                    'timezone' => 'America/Sao_Paulo',
                    'currency' => 'BRL',
                ],
            ]);

        $this->assertStringContainsString('"percentage":{"value":50.0,"previous":50.0,"change":0.0}', $response->getContent());
        $this->assertStringNotContainsString('organization_id', $response->getContent());
    }

    public function test_statuses_are_always_listed_in_the_same_order(): void
    {
        $this->transaction(TransactionStatus::Canceled, '10.00', '2026-09-10 15:00:00');
        $this->transaction(TransactionStatus::Pending, '10.00', '2026-09-10 15:00:00');

        $this->statuses(self::SEPTEMBER)->assertOk()->assertJsonPath('data.*.status', self::STATUSES);
        $this->statuses('from=2026-01-01&to=2026-01-31')->assertOk()->assertJsonPath('data.*.status', self::STATUSES);
    }

    // Values

    public function test_orders_count_transactions_not_items_and_revenue_uses_total_amount(): void
    {
        $this->createTransaction($this->customer, [[$this->product, 3, '10.00'], [$this->product, 1, '5.50']], TransactionStatus::Refunded, '2026-09-10 15:00:00');

        $this->statuses(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.1.orders.value', 1)
            ->assertJsonPath('data.1.revenue.value', '35.50');
    }

    public function test_period_without_transactions_has_zero_orders_and_undefined_percentages(): void
    {
        $response = $this->statuses(self::SEPTEMBER)->assertOk();

        foreach (self::STATUSES as $index => $status) {
            $response->assertJsonPath("data.{$index}", [
                'status' => $status,
                'orders' => ['value' => 0, 'previous' => 0, 'change' => 0.0],
                'revenue' => ['value' => '0.00', 'previous' => '0.00', 'change' => 0.0],
                'percentage' => ['value' => null, 'previous' => null, 'change' => null],
            ]);
        }
    }

    public function test_empty_previous_period_leaves_previous_percentages_undefined(): void
    {
        $this->transaction(TransactionStatus::Paid, '10.00', '2026-09-10 15:00:00');

        $this->statuses(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.0.orders', ['value' => 1, 'previous' => 0, 'change' => null])
            ->assertJsonPath('data.0.percentage', ['value' => 100.0, 'previous' => null, 'change' => null])
            ->assertJsonPath('data.1.orders', ['value' => 0, 'previous' => 0, 'change' => 0.0])
            ->assertJsonPath('data.1.percentage', ['value' => 0.0, 'previous' => null, 'change' => null]);
    }

    public function test_empty_current_period_leaves_current_percentages_undefined(): void
    {
        $this->transaction(TransactionStatus::Refunded, '10.00', '2026-08-10 15:00:00');

        $this->statuses(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.1.orders', ['value' => 0, 'previous' => 1, 'change' => -100.0])
            ->assertJsonPath('data.1.revenue', ['value' => '0.00', 'previous' => '10.00', 'change' => -100.0])
            ->assertJsonPath('data.1.percentage', ['value' => null, 'previous' => 100.0, 'change' => null])
            ->assertJsonPath('data.0.percentage', ['value' => null, 'previous' => 0.0, 'change' => null]);
    }

    public function test_percentages_are_rounded_shares_of_the_period_orders(): void
    {
        $this->transaction(TransactionStatus::Paid, '10.00', '2026-09-10 15:00:00');
        $this->transaction(TransactionStatus::Refunded, '10.00', '2026-09-10 15:00:00');
        $this->transaction(TransactionStatus::Refunded, '10.00', '2026-09-11 15:00:00');

        $this->statuses(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.*.percentage.value', [33.3, 66.7, 0.0, 0.0]);
    }

    public function test_sales_outside_both_periods_are_ignored(): void
    {
        $this->transaction(TransactionStatus::Paid, '10.00', '2026-09-10 15:00:00');
        $this->transaction(TransactionStatus::Paid, '70.00', '2026-08-01 15:00:00');
        $this->transaction(TransactionStatus::Canceled, '70.00', '2026-10-01 15:00:00');

        $this->statuses(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.0.orders', ['value' => 1, 'previous' => 0, 'change' => null])
            ->assertJsonPath('data.3.orders', ['value' => 0, 'previous' => 0, 'change' => 0.0]);
    }

    // Comparison

    public function test_growth_drop_and_stability_between_periods(): void
    {
        foreach (['2026-09-10', '2026-09-11', '2026-09-12'] as $day) {
            $this->transaction(TransactionStatus::Paid, '10.00', "{$day} 15:00:00");
        }
        foreach (['2026-08-10', '2026-08-11'] as $day) {
            $this->transaction(TransactionStatus::Paid, '10.00', "{$day} 15:00:00");
            $this->transaction(TransactionStatus::Refunded, '25.00', "{$day} 15:00:00");
        }
        $this->transaction(TransactionStatus::Refunded, '25.00', '2026-09-10 15:00:00');
        $this->transaction(TransactionStatus::Pending, '5.00', '2026-09-10 15:00:00');
        $this->transaction(TransactionStatus::Pending, '5.00', '2026-08-10 15:00:00');

        // Current: 3 paid, 1 refunded, 1 pending (5). Previous: 2 paid, 2 refunded, 1 pending (5).
        $this->statuses(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.0.orders', ['value' => 3, 'previous' => 2, 'change' => 50.0])
            ->assertJsonPath('data.0.revenue', ['value' => '30.00', 'previous' => '20.00', 'change' => 50.0])
            ->assertJsonPath('data.0.percentage', ['value' => 60.0, 'previous' => 40.0, 'change' => 50.0])
            ->assertJsonPath('data.1.orders', ['value' => 1, 'previous' => 2, 'change' => -50.0])
            ->assertJsonPath('data.1.revenue', ['value' => '25.00', 'previous' => '50.00', 'change' => -50.0])
            ->assertJsonPath('data.1.percentage', ['value' => 20.0, 'previous' => 40.0, 'change' => -50.0])
            ->assertJsonPath('data.2.orders', ['value' => 1, 'previous' => 1, 'change' => 0.0])
            ->assertJsonPath('data.2.percentage', ['value' => 20.0, 'previous' => 20.0, 'change' => 0.0])
            ->assertJsonPath('data.3.orders', ['value' => 0, 'previous' => 0, 'change' => 0.0])
            ->assertJsonPath('data.3.percentage', ['value' => 0.0, 'previous' => 0.0, 'change' => 0.0]);
    }

    // Defaults

    public function test_defaults_to_the_last_30_days_in_the_organization_timezone(): void
    {
        // 01:30 UTC on Oct 1st is still Sep 30th in São Paulo.
        Carbon::setTestNow('2026-10-01 01:30:00');
        $this->transaction(TransactionStatus::Pending, '25.00', '2026-10-01 01:00:00');

        $this->statuses()
            ->assertOk()
            ->assertJsonPath('meta.period', ['from' => '2026-09-01', 'to' => '2026-09-30', 'days' => 30])
            ->assertJsonPath('data.2.orders.value', 1)
            ->assertJsonPath('data.2.revenue.value', '25.00');
    }

    // Timezone

    public function test_sao_paulo_midnight_splits_current_and_previous_periods(): void
    {
        $this->transaction(TransactionStatus::Paid, '10.00', '2026-09-01 02:59:59'); // Aug 31st 23:59:59 local
        $this->transaction(TransactionStatus::Canceled, '20.00', '2026-09-01 03:00:00'); // Sep 1st 00:00 local

        $this->statuses('from=2026-09-01&to=2026-09-01')
            ->assertOk()
            ->assertJsonPath('data.0.orders', ['value' => 0, 'previous' => 1, 'change' => -100.0])
            ->assertJsonPath('data.3.orders', ['value' => 1, 'previous' => 0, 'change' => null])
            ->assertJsonPath('data.3.percentage', ['value' => 100.0, 'previous' => 0.0, 'change' => null]);
    }

    public function test_lisbon_midnight_decides_the_period(): void
    {
        $this->organization->update(['timezone' => 'Europe/Lisbon']);

        // Lisbon is UTC+1 in September.
        $this->transaction(TransactionStatus::Paid, '10.00', '2026-09-09 22:59:59'); // Sep 9th 23:59:59 local
        $this->transaction(TransactionStatus::Paid, '20.00', '2026-09-09 23:00:00'); // Sep 10th 00:00 local

        $this->statuses('from=2026-09-10&to=2026-09-10')
            ->assertOk()
            ->assertJsonPath('meta.timezone', 'Europe/Lisbon')
            ->assertJsonPath('data.0.revenue', ['value' => '20.00', 'previous' => '10.00', 'change' => 100.0]);
    }

    public function test_lisbon_daylight_saving_day_has_23_hours(): void
    {
        $this->organization->update(['timezone' => 'Europe/Lisbon']);

        // Lisbon switches from UTC+0 to UTC+1 on 2026-03-29 at 01:00 UTC.
        $this->transaction(TransactionStatus::Paid, '10.00', '2026-03-28 23:59:59'); // Mar 28th 23:59:59 WET
        $this->transaction(TransactionStatus::Paid, '20.00', '2026-03-29 00:00:00'); // Mar 29th 00:00 WET
        $this->transaction(TransactionStatus::Refunded, '40.00', '2026-03-29 22:59:59'); // Mar 29th 23:59:59 WEST
        $this->transaction(TransactionStatus::Refunded, '80.00', '2026-03-29 23:00:00'); // Mar 30th 00:00 WEST

        $this->statuses('from=2026-03-29&to=2026-03-29')
            ->assertOk()
            ->assertJsonPath('meta.period', ['from' => '2026-03-29', 'to' => '2026-03-29', 'days' => 1])
            ->assertJsonPath('data.0.revenue', ['value' => '20.00', 'previous' => '10.00', 'change' => 100.0])
            ->assertJsonPath('data.1.revenue', ['value' => '40.00', 'previous' => '0.00', 'change' => null])
            ->assertJsonPath('data.*.percentage.value', [50.0, 50.0, 0.0, 0.0]);
    }

    // Tenant isolation and access

    public function test_other_organization_transactions_are_never_included(): void
    {
        $foreign = Organization::factory()->create();
        $foreignOwner = $this->memberOf($foreign);
        $foreignCustomer = Customer::factory()->for($foreign)->create();
        $foreignProduct = Product::factory()->for($foreign)->create();
        $this->createTransaction($foreignCustomer, [[$foreignProduct, 1, '900.00']], TransactionStatus::Canceled, '2026-09-10 15:00:00');
        $this->createTransaction($foreignCustomer, [[$foreignProduct, 1, '700.00']], TransactionStatus::Paid, '2026-08-10 15:00:00');

        $this->transaction(TransactionStatus::Paid, '10.00', '2026-09-10 15:00:00');

        $this->statuses(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.0.orders', ['value' => 1, 'previous' => 0, 'change' => null])
            ->assertJsonPath('data.0.revenue', ['value' => '10.00', 'previous' => '0.00', 'change' => null])
            ->assertJsonPath('data.0.percentage', ['value' => 100.0, 'previous' => null, 'change' => null])
            ->assertJsonPath('data.3.orders', ['value' => 0, 'previous' => 0, 'change' => 0.0])
            ->assertJsonPath('data.3.revenue.value', '0.00');

        $this->actingInOrganization($foreignOwner, $foreign)
            ->getJson(self::URI.'?'.self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.0.orders', ['value' => 0, 'previous' => 1, 'change' => -100.0])
            ->assertJsonPath('data.0.revenue.previous', '700.00')
            ->assertJsonPath('data.3.orders', ['value' => 1, 'previous' => 0, 'change' => null])
            ->assertJsonPath('data.3.revenue.value', '900.00');
    }

    public function test_organization_header_without_membership_is_forbidden(): void
    {
        $this->actingInOrganization($this->owner, Organization::factory()->create())
            ->getJson(self::URI)
            ->assertForbidden();
    }

    public function test_guest_receives_401(): void
    {
        $this->withHeader('X-Organization-Id', $this->organization->id)
            ->getJson(self::URI)
            ->assertUnauthorized();
    }

    public function test_owner_and_member_can_read_transaction_status_analytics(): void
    {
        $member = $this->memberOf($this->organization, Organization::ROLE_MEMBER);
        $this->transaction(TransactionStatus::Pending, '10.00', '2026-09-10 15:00:00');

        foreach ([$this->owner, $member] as $user) {
            $this->actingInOrganization($user, $this->organization)
                ->getJson(self::URI.'?'.self::SEPTEMBER)
                ->assertOk()
                ->assertJsonPath('data.2.orders.value', 1);
        }
    }

    // Validation

    public function test_rejects_invalid_periods(): void
    {
        $this->statuses('from=01/09/2026&to=2026-02-30')->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
        $this->statuses('from=2026-09-01')->assertUnprocessable()->assertJsonValidationErrors(['to']);
        $this->statuses('to=2026-09-01')->assertUnprocessable()->assertJsonValidationErrors(['from']);
        $this->statuses('from=2026-09-10&to=2026-09-01')->assertUnprocessable()->assertJsonValidationErrors(['to']);
        $this->statuses('from=2025-01-01&to=2026-01-02')->assertUnprocessable()->assertJsonValidationErrors(['to']);
    }

    public function test_organization_id_is_rejected_and_timezone_parameter_is_ignored(): void
    {
        $this->statuses(self::SEPTEMBER.'&organization_id='.Organization::factory()->create()->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['organization_id']);

        $this->transaction(TransactionStatus::Paid, '10.00', '2026-09-01 02:30:00'); // Aug 31st 23:30 local

        $this->statuses('from=2026-09-01&to=2026-09-01&timezone=UTC')
            ->assertOk()
            ->assertJsonPath('meta.timezone', 'America/Sao_Paulo')
            ->assertJsonPath('data.0.orders', ['value' => 0, 'previous' => 1, 'change' => -100.0]);
    }

    // Read-only

    public function test_write_methods_are_not_allowed(): void
    {
        $this->actingInOrganization($this->owner, $this->organization);

        $this->postJson(self::URI)->assertMethodNotAllowed();
        $this->putJson(self::URI)->assertMethodNotAllowed();
        $this->patchJson(self::URI)->assertMethodNotAllowed();
        $this->deleteJson(self::URI)->assertMethodNotAllowed();
    }

    public function test_route_only_accepts_get_and_head(): void
    {
        $methods = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteDefinition $route) => $route->uri() === 'api/v1/analytics/transactions')
            ->flatMap(fn (RouteDefinition $route) => $route->methods())
            ->values()
            ->all();

        $this->assertSame(['GET', 'HEAD'], $methods);
    }

    // Queries

    public function test_uses_one_aggregate_query_regardless_of_data_and_period(): void
    {
        $this->transaction(TransactionStatus::Paid, '10.00', '2026-09-10 15:00:00');
        $few = $this->queries(self::SEPTEMBER);

        foreach (range(1, 9) as $day) {
            foreach (TransactionStatus::cases() as $status) {
                $this->transaction($status, '10.00', "2026-09-0{$day} 15:00:00");
                $this->transaction($status, '10.00', "2026-08-1{$day} 15:00:00");
            }
        }
        $many = $this->queries(self::SEPTEMBER);
        $longPeriod = $this->queries('from=2025-10-01&to=2026-09-30');

        foreach (['few' => $few, 'many' => $many, 'long period' => $longPeriod] as $label => $queries) {
            $this->assertCount(1, array_filter($queries, fn (string $sql) => str_contains($sql, 'from "transactions"')), $label);
            $this->assertCount(0, array_filter($queries, fn (string $sql) => str_contains($sql, '"transaction_items"') || str_contains($sql, '"customers"')), $label);
        }
    }

    private function statuses(string $query = ''): TestResponse
    {
        return $this->actingInOrganization($this->owner, $this->organization)
            ->getJson(self::URI.($query === '' ? '' : "?{$query}"));
    }

    private function transaction(TransactionStatus $status, string $amount, string $occurredAt): void
    {
        $this->createTransaction($this->customer, [[$this->product, 1, $amount]], $status, $occurredAt);
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

        $this->statuses($query)->assertOk();

        $queries = collect(DB::getQueryLog())->pluck('query')->values()->all();

        DB::disableQueryLog();

        return $queries;
    }
}
