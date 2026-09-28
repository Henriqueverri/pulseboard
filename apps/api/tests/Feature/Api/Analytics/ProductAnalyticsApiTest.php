<?php

namespace Tests\Feature\Api\Analytics;

use App\Enums\ProductStatus;
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

class ProductAnalyticsApiTest extends TestCase
{
    use InteractsWithOrganizationApi, RefreshDatabase;

    private const SEPTEMBER = 'from=2026-09-01&to=2026-09-30';

    private Organization $organization;

    private User $owner;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->owner = $this->memberOf($this->organization);
        $this->customer = Customer::factory()->for($this->organization)->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // Contract

    public function test_returns_ranking_with_comparisons_summary_and_meta(): void
    {
        $alpha = $this->product('Alpha');
        $bravo = $this->product('Bravo');
        $charlie = $this->product('Charlie');

        $this->createTransaction($this->customer, [[$alpha, 2, '100.00'], [$bravo, 3, '20.00']], occurredAt: '2026-09-10 15:00:00');
        $this->sell($alpha, 1, '100.00', '2026-08-20 15:00:00');
        $this->sell($charlie, 1, '50.00', '2026-08-25 15:00:00');

        $response = $this->products(self::SEPTEMBER)
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    [
                        'rank' => 1,
                        'product' => ['id' => $alpha->id, 'name' => 'Alpha', 'sku' => $alpha->sku, 'status' => 'active', 'is_deleted' => false],
                        'revenue' => ['value' => '200.00', 'previous' => '100.00', 'change' => 100.0],
                        'units_sold' => ['value' => 2, 'previous' => 1, 'change' => 100.0],
                    ],
                    [
                        'rank' => 2,
                        'product' => ['id' => $bravo->id, 'name' => 'Bravo', 'sku' => $bravo->sku, 'status' => 'active', 'is_deleted' => false],
                        'revenue' => ['value' => '60.00', 'previous' => '0.00', 'change' => null],
                        'units_sold' => ['value' => 3, 'previous' => 0, 'change' => null],
                    ],
                ],
                'summary' => [
                    'revenue' => ['value' => '260.00', 'previous' => '150.00', 'change' => 73.3],
                    'units_sold' => ['value' => 5, 'previous' => 2, 'change' => 150.0],
                    'products_sold' => ['value' => 2, 'previous' => 2, 'change' => 0.0],
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

        $this->assertStringContainsString('"change":100.0', $response->getContent());
        $this->assertStringContainsString('"change":0.0', $response->getContent());
        $this->assertStringNotContainsString('organization_id', $response->getContent());
    }

    // Paid rule and aggregation

    public function test_counts_only_items_of_paid_transactions(): void
    {
        $product = $this->product('Alpha');

        $this->sell($product, 1, '10.00', '2026-09-10 15:00:00');
        $this->sell($product, 5, '10.00', '2026-09-10 15:00:00', TransactionStatus::Pending);
        $this->sell($product, 5, '10.00', '2026-09-10 15:00:00', TransactionStatus::Refunded);
        $this->sell($product, 5, '10.00', '2026-09-10 15:00:00', TransactionStatus::Canceled);
        $this->sell($this->product('Bravo'), 9, '10.00', '2026-09-10 15:00:00', TransactionStatus::Pending);

        $this->products(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.revenue.value', '10.00')
            ->assertJsonPath('data.0.units_sold.value', 1)
            ->assertJsonPath('summary.revenue.value', '10.00')
            ->assertJsonPath('summary.units_sold.value', 1)
            ->assertJsonPath('summary.products_sold.value', 1);
    }

    public function test_sums_quantities_and_line_totals_across_transactions(): void
    {
        $alpha = $this->product('Alpha');
        $bravo = $this->product('Bravo');

        $this->createTransaction($this->customer, [[$alpha, 2, '15.50'], [$bravo, 1, '99.90']], occurredAt: '2026-09-03 15:00:00');
        $this->createTransaction($this->customer, [[$alpha, 3, '15.50']], occurredAt: '2026-09-20 15:00:00');

        $this->products(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.*.product.name', ['Bravo', 'Alpha'])
            ->assertJsonPath('data.0.revenue.value', '99.90')
            ->assertJsonPath('data.1.revenue.value', '77.50')
            ->assertJsonPath('data.1.units_sold.value', 5)
            ->assertJsonPath('summary.revenue.value', '177.40')
            ->assertJsonPath('summary.units_sold.value', 6);
    }

    public function test_revenue_uses_the_price_at_sale_time(): void
    {
        $product = $this->product('Alpha', '100.00');
        $this->sell($product, 2, '100.00', '2026-09-10 15:00:00');

        $product->update(['price' => '999.00']);

        $this->products(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.0.revenue.value', '200.00');
    }

    // Sorting

    public function test_sort_ranks_by_revenue_or_units_sold(): void
    {
        $premium = $this->product('Premium');
        $cheap = $this->product('Cheap');
        $this->sell($premium, 1, '500.00', '2026-09-10 15:00:00');
        $this->sell($cheap, 10, '10.00', '2026-09-10 15:00:00');

        $this->products(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.*.product.name', ['Premium', 'Cheap'])
            ->assertJsonPath('data.*.rank', [1, 2]);

        $this->products(self::SEPTEMBER.'&sort=units_sold')
            ->assertOk()
            ->assertJsonPath('data.*.product.name', ['Cheap', 'Premium'])
            ->assertJsonPath('data.*.rank', [1, 2])
            ->assertJsonPath('meta.sort', 'units_sold');
    }

    public function test_revenue_ties_are_broken_by_units_then_name_then_id(): void
    {
        $this->sell($this->product('Banana'), 1, '100.00', '2026-09-10 15:00:00');
        $this->sell($this->product('Apple'), 1, '100.00', '2026-09-10 15:00:00');
        $this->sell($this->product('Zucchini'), 4, '25.00', '2026-09-10 15:00:00');
        $first = $this->product('Same');
        $second = $this->product('Same');
        $this->sell($first, 1, '50.00', '2026-09-10 15:00:00');
        $this->sell($second, 1, '50.00', '2026-09-10 15:00:00');

        $ids = [$first->id, $second->id];
        sort($ids);

        $response = $this->products(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.*.product.name', ['Zucchini', 'Apple', 'Banana', 'Same', 'Same']);

        $this->assertSame($ids, [$response->json('data.3.product.id'), $response->json('data.4.product.id')]);
    }

    public function test_units_ties_are_broken_by_revenue_then_name(): void
    {
        $this->sell($this->product('Low'), 2, '10.00', '2026-09-10 15:00:00');
        $this->sell($this->product('High'), 2, '50.00', '2026-09-10 15:00:00');
        $this->sell($this->product('Beta'), 1, '10.00', '2026-09-10 15:00:00');
        $this->sell($this->product('Alpha'), 1, '10.00', '2026-09-10 15:00:00');

        $this->products(self::SEPTEMBER.'&sort=units_sold')
            ->assertOk()
            ->assertJsonPath('data.*.product.name', ['High', 'Low', 'Alpha', 'Beta']);
    }

    // Limit

    public function test_limit_defaults_to_ten_and_never_affects_the_summary(): void
    {
        foreach (range(1, 12) as $n) {
            $this->sell($this->product(sprintf('Product %02d', $n)), 1, "{$n}.00", '2026-09-10 15:00:00');
        }

        $this->products(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('data.0.product.name', 'Product 12')
            ->assertJsonPath('meta.limit', 10)
            ->assertJsonPath('summary.products_sold.value', 12);

        $this->products(self::SEPTEMBER.'&limit=2')
            ->assertOk()
            ->assertJsonPath('data.*.product.name', ['Product 12', 'Product 11'])
            ->assertJsonPath('meta.limit', 2)
            ->assertJsonPath('summary.revenue.value', '78.00')
            ->assertJsonPath('summary.units_sold.value', 12)
            ->assertJsonPath('summary.products_sold.value', 12);

        $this->products(self::SEPTEMBER.'&limit=50')->assertOk()->assertJsonCount(12, 'data');
    }

    // Previous period

    public function test_period_without_sales_returns_an_empty_ranking_and_zero_summary(): void
    {
        $this->products(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('summary', [
                'revenue' => ['value' => '0.00', 'previous' => '0.00', 'change' => 0.0],
                'units_sold' => ['value' => 0, 'previous' => 0, 'change' => 0.0],
                'products_sold' => ['value' => 0, 'previous' => 0, 'change' => 0.0],
            ]);
    }

    public function test_products_sold_only_in_the_previous_period_are_not_ranked(): void
    {
        $this->sell($this->product('Alpha'), 1, '80.00', '2026-08-20 15:00:00');

        $this->products(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('summary', [
                'revenue' => ['value' => '0.00', 'previous' => '80.00', 'change' => -100.0],
                'units_sold' => ['value' => 0, 'previous' => 1, 'change' => -100.0],
                'products_sold' => ['value' => 0, 'previous' => 1, 'change' => -100.0],
            ]);
    }

    public function test_sales_outside_both_periods_are_ignored(): void
    {
        $product = $this->product('Alpha');
        $this->sell($product, 1, '10.00', '2026-09-10 15:00:00');
        $this->sell($product, 7, '10.00', '2026-08-01 15:00:00');
        $this->sell($product, 7, '10.00', '2026-10-01 15:00:00');

        $this->products(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.0.units_sold', ['value' => 1, 'previous' => 0, 'change' => null])
            ->assertJsonPath('summary.units_sold', ['value' => 1, 'previous' => 0, 'change' => null]);
    }

    // Soft deletes and status

    public function test_soft_deleted_and_inactive_products_keep_their_sales(): void
    {
        $deleted = $this->product('Deleted');
        $inactive = $this->product('Inactive');
        $inactive->update(['status' => ProductStatus::Inactive]);
        $this->sell($deleted, 2, '30.00', '2026-09-10 15:00:00');
        $this->sell($inactive, 1, '40.00', '2026-09-10 15:00:00');
        $deleted->delete();

        $this->assertSoftDeleted($deleted);

        $this->products(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.0.product', ['id' => $deleted->id, 'name' => 'Deleted', 'sku' => $deleted->sku, 'status' => 'active', 'is_deleted' => true])
            ->assertJsonPath('data.0.revenue.value', '60.00')
            ->assertJsonPath('data.1.product.status', 'inactive')
            ->assertJsonPath('data.1.product.is_deleted', false)
            ->assertJsonPath('summary.revenue.value', '100.00');
    }

    public function test_purchases_of_soft_deleted_customers_still_count(): void
    {
        $gone = Customer::factory()->for($this->organization)->create();
        $this->createTransaction($gone, [[$this->product('Alpha'), 2, '10.00']], occurredAt: '2026-09-10 15:00:00');
        $gone->delete();

        $this->products(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.0.units_sold.value', 2)
            ->assertJsonPath('summary.revenue.value', '20.00');
    }

    // Defaults

    public function test_defaults_to_the_last_30_days_in_the_organization_timezone(): void
    {
        // 01:30 UTC on Oct 1st is still Sep 30th in São Paulo.
        Carbon::setTestNow('2026-10-01 01:30:00');
        $this->sell($this->product('Alpha'), 1, '25.00', '2026-10-01 01:00:00');

        $this->products()
            ->assertOk()
            ->assertJsonPath('meta.period', ['from' => '2026-09-01', 'to' => '2026-09-30', 'days' => 30])
            ->assertJsonPath('meta.sort', 'revenue')
            ->assertJsonPath('meta.limit', 10)
            ->assertJsonPath('data.0.revenue.value', '25.00');
    }

    // Timezone

    public function test_sao_paulo_midnight_splits_current_and_previous_periods(): void
    {
        $this->sell($this->product('Before'), 1, '10.00', '2026-09-01 02:59:59'); // Aug 31st 23:59:59 local
        $this->sell($this->product('After'), 1, '20.00', '2026-09-01 03:00:00'); // Sep 1st 00:00 local

        $this->products('from=2026-09-01&to=2026-09-01')
            ->assertOk()
            ->assertJsonPath('data.*.product.name', ['After'])
            ->assertJsonPath('summary.revenue', ['value' => '20.00', 'previous' => '10.00', 'change' => 100.0])
            ->assertJsonPath('summary.products_sold.previous', 1);
    }

    public function test_lisbon_midnight_decides_the_period(): void
    {
        $this->organization->update(['timezone' => 'Europe/Lisbon']);

        // Lisbon is UTC+1 in September.
        $this->sell($this->product('Before'), 1, '10.00', '2026-09-09 22:59:59'); // Sep 9th 23:59:59 local
        $this->sell($this->product('After'), 1, '20.00', '2026-09-09 23:00:00'); // Sep 10th 00:00 local

        $this->products('from=2026-09-10&to=2026-09-10')
            ->assertOk()
            ->assertJsonPath('meta.timezone', 'Europe/Lisbon')
            ->assertJsonPath('data.*.product.name', ['After'])
            ->assertJsonPath('summary.revenue.previous', '10.00');
    }

    public function test_lisbon_daylight_saving_day_has_23_hours(): void
    {
        $this->organization->update(['timezone' => 'Europe/Lisbon']);
        $product = $this->product('Alpha');

        // Lisbon switches from UTC+0 to UTC+1 on 2026-03-29 at 01:00 UTC.
        $this->sell($product, 1, '10.00', '2026-03-28 23:59:59'); // Mar 28th 23:59:59 WET
        $this->sell($product, 2, '10.00', '2026-03-29 00:00:00'); // Mar 29th 00:00 WET
        $this->sell($product, 4, '10.00', '2026-03-29 22:59:59'); // Mar 29th 23:59:59 WEST
        $this->sell($product, 8, '10.00', '2026-03-29 23:00:00'); // Mar 30th 00:00 WEST

        $this->products('from=2026-03-29&to=2026-03-29')
            ->assertOk()
            ->assertJsonPath('meta.period', ['from' => '2026-03-29', 'to' => '2026-03-29', 'days' => 1])
            ->assertJsonPath('data.0.units_sold', ['value' => 6, 'previous' => 1, 'change' => 500.0])
            ->assertJsonPath('summary.revenue', ['value' => '60.00', 'previous' => '10.00', 'change' => 500.0]);
    }

    // Tenant isolation and access

    public function test_other_organization_products_and_sales_are_never_included(): void
    {
        $foreign = Organization::factory()->create();
        $foreignOwner = $this->memberOf($foreign);
        $foreignCustomer = Customer::factory()->for($foreign)->create();
        $foreignProduct = Product::factory()->for($foreign)->create(['name' => 'Foreign']);
        $this->createTransaction($foreignCustomer, [[$foreignProduct, 9, '100.00']], occurredAt: '2026-09-10 15:00:00');
        $this->createTransaction($foreignCustomer, [[$foreignProduct, 1, '100.00']], occurredAt: '2026-08-10 15:00:00');

        $this->sell($this->product('Local'), 1, '10.00', '2026-09-10 15:00:00');

        $response = $this->products(self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.*.product.name', ['Local'])
            ->assertJsonPath('summary.revenue', ['value' => '10.00', 'previous' => '0.00', 'change' => null])
            ->assertJsonPath('summary.units_sold.value', 1)
            ->assertJsonPath('summary.products_sold.previous', 0);

        $this->assertStringNotContainsString($foreignProduct->id, $response->getContent());

        $this->actingInOrganization($foreignOwner, $foreign)
            ->getJson('/api/v1/analytics/products?'.self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.*.product.name', ['Foreign'])
            ->assertJsonPath('summary.revenue', ['value' => '900.00', 'previous' => '100.00', 'change' => 800.0]);
    }

    public function test_organization_header_without_membership_is_forbidden(): void
    {
        $this->actingInOrganization($this->owner, Organization::factory()->create())
            ->getJson('/api/v1/analytics/products')
            ->assertForbidden();
    }

    public function test_guest_receives_401(): void
    {
        $this->withHeader('X-Organization-Id', $this->organization->id)
            ->getJson('/api/v1/analytics/products')
            ->assertUnauthorized();
    }

    public function test_member_role_can_read_product_analytics(): void
    {
        $member = $this->memberOf($this->organization, Organization::ROLE_MEMBER);
        $this->sell($this->product('Alpha'), 1, '10.00', '2026-09-10 15:00:00');

        $this->actingInOrganization($member, $this->organization)
            ->getJson('/api/v1/analytics/products?'.self::SEPTEMBER)
            ->assertOk()
            ->assertJsonPath('data.0.revenue.value', '10.00');
    }

    // Validation

    public function test_rejects_invalid_sort_limit_and_periods(): void
    {
        $this->products(self::SEPTEMBER.'&sort=price')->assertUnprocessable()->assertJsonValidationErrors(['sort']);
        $this->products(self::SEPTEMBER.'&limit=0')->assertUnprocessable()->assertJsonValidationErrors(['limit']);
        $this->products(self::SEPTEMBER.'&limit=51')->assertUnprocessable()->assertJsonValidationErrors(['limit']);
        $this->products(self::SEPTEMBER.'&limit=abc')->assertUnprocessable()->assertJsonValidationErrors(['limit']);
        $this->products('from=01/09/2026&to=2026-02-30')->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
        $this->products('from=2026-09-01')->assertUnprocessable()->assertJsonValidationErrors(['to']);
        $this->products('from=2026-09-10&to=2026-09-01')->assertUnprocessable()->assertJsonValidationErrors(['to']);
        $this->products('from=2025-01-01&to=2026-01-02')->assertUnprocessable()->assertJsonValidationErrors(['to']);
    }

    public function test_organization_id_is_rejected_and_timezone_parameter_is_ignored(): void
    {
        $this->products(self::SEPTEMBER.'&organization_id='.Organization::factory()->create()->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['organization_id']);

        $this->sell($this->product('Alpha'), 1, '10.00', '2026-09-01 02:30:00'); // Aug 31st 23:30 local

        $this->products('from=2026-09-01&to=2026-09-01&timezone=UTC')
            ->assertOk()
            ->assertJsonPath('meta.timezone', 'America/Sao_Paulo')
            ->assertJsonPath('data', [])
            ->assertJsonPath('summary.revenue.previous', '10.00');
    }

    // Queries

    public function test_uses_three_transaction_queries_and_one_product_query_regardless_of_data(): void
    {
        $this->sell($this->product('Alpha'), 1, '10.00', '2026-09-10 15:00:00');
        $few = $this->queries(self::SEPTEMBER);

        foreach (range(1, 20) as $n) {
            $product = $this->product("Product {$n}");
            $this->sell($product, 1, '10.00', '2026-09-1'.($n % 10).' 15:00:00');
            $this->sell($product, 2, '10.00', '2026-08-1'.($n % 10).' 15:00:00');
        }
        $many = $this->queries(self::SEPTEMBER.'&limit=50&sort=units_sold');

        foreach (['few' => $few, 'many' => $many] as $label => $queries) {
            $this->assertCount(3, array_filter($queries, fn (string $sql) => str_contains($sql, 'from "transactions"')), $label);
            $this->assertCount(1, array_filter($queries, fn (string $sql) => str_contains($sql, '"products"')), $label);
        }
    }

    private function products(string $query = ''): TestResponse
    {
        return $this->actingInOrganization($this->owner, $this->organization)
            ->getJson('/api/v1/analytics/products'.($query === '' ? '' : "?{$query}"));
    }

    private function product(string $name, string $price = '10.00'): Product
    {
        return Product::factory()->for($this->organization)->create(['name' => $name, 'price' => $price]);
    }

    private function sell(Product $product, int $quantity, string $unitPrice, string $occurredAt, TransactionStatus $status = TransactionStatus::Paid): void
    {
        $this->createTransaction($this->customer, [[$product, $quantity, $unitPrice]], $status, $occurredAt);
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

        $this->products($query)->assertOk();

        $queries = collect(DB::getQueryLog())->pluck('query')->values()->all();

        DB::disableQueryLog();

        return $queries;
    }
}
