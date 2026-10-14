<?php

namespace Tests\Feature\Api\Analytics;

use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

class InventoryAnalyticsApiTest extends TestCase
{
    use InteractsWithOrganizationApi, RefreshDatabase;

    private const URI = '/api/v1/analytics/inventory';

    private Organization $organization;

    private User $owner;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // 12:00 in São Paulo: the 28-day window is 2026-09-16 to 2026-10-13, local days.
        Carbon::setTestNow('2026-10-13 15:00:00');

        $this->organization = Organization::factory()->create(['timezone' => 'America/Sao_Paulo']);
        $this->owner = $this->memberOf($this->organization);
        $this->customer = Customer::factory()->for($this->organization)->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_lists_tracked_products_most_urgent_first_with_days_of_cover(): void
    {
        $water = Product::factory()->for($this->organization)->stocked(0, 4)->create(['name' => 'Água sem gás', 'sku' => 'BAR-AGU']);
        $heineken = Product::factory()->for($this->organization)->stocked(3, 6)->create(['name' => 'Heineken', 'sku' => 'BAR-HEI']);
        $skol = Product::factory()->for($this->organization)->stocked(5, 6)->create(['name' => 'Skol', 'sku' => 'BAR-SKO']);
        $corona = Product::factory()->for($this->organization)->stocked(24, 6)->create(['name' => 'Corona', 'sku' => 'BAR-COR']);

        $this->sell($heineken, 56, '2026-10-01 20:00:00');
        $this->sell($skol, 14, '2026-10-01 20:00:00');
        $this->sell($corona, 28, '2026-10-01 20:00:00');

        $this->getInventory()
            ->assertOk()
            ->assertJsonPath('data.summary', ['tracked' => 4, 'ok' => 1, 'low' => 2, 'out' => 1])
            ->assertJsonPath('data.items.*.product.sku', ['BAR-AGU', 'BAR-HEI', 'BAR-SKO', 'BAR-COR'])
            ->assertJsonPath('data.items.*.status', ['out', 'low', 'low', 'ok'])
            ->assertJsonPath('data.items.*.days_of_cover', [null, 1.5, 10.0, 24.0])
            ->assertJsonPath('data.items.1', [
                'product' => ['id' => $heineken->id, 'name' => 'Heineken', 'sku' => 'BAR-HEI'],
                'quantity' => 3,
                'min_quantity' => 6,
                'status' => 'low',
                'units_sold' => 56,
                'average_daily_units' => 2.0,
                'days_of_cover' => 1.5,
                'updated_at' => '2026-10-13T15:00:00Z',
            ])
            ->assertJsonPath('data.items.0.units_sold', 0)
            ->assertJsonPath('data.items.0.product.id', $water->id)
            ->assertJsonPath('meta', [
                'sales_window' => ['from' => '2026-09-16', 'to' => '2026-10-13', 'days' => 28],
                'timezone' => 'America/Sao_Paulo',
            ]);
    }

    public function test_only_paid_sales_inside_the_window_count(): void
    {
        $product = Product::factory()->for($this->organization)->stocked(10)->create();

        // Midnight of 2026-09-16 in São Paulo is the first instant of the window.
        $this->sell($product, 1, '2026-09-16 03:00:00');
        $this->sell($product, 100, '2026-09-16 02:59:59');
        $this->sell($product, 100, '2026-10-01 20:00:00', TransactionStatus::Refunded);
        $this->sell($product, 100, '2026-10-01 20:00:00', TransactionStatus::Pending);
        $this->sell($product, 100, '2026-10-01 20:00:00', TransactionStatus::Canceled);

        $this->getInventory()
            ->assertOk()
            ->assertJsonPath('data.items.0.units_sold', 1);
    }

    public function test_products_without_stock_control_or_deleted_are_left_out(): void
    {
        Product::factory()->for($this->organization)->create();
        Product::factory()->for($this->organization)->stocked(5)->create()->delete();
        $kept = Product::factory()->for($this->organization)->stocked(5)->create();

        $this->getInventory()
            ->assertOk()
            ->assertJsonPath('data.summary.tracked', 1)
            ->assertJsonPath('data.items.*.product.id', [$kept->id]);
    }

    public function test_an_organization_without_stock_control_gets_an_empty_report(): void
    {
        $this->getInventory()
            ->assertOk()
            ->assertJsonPath('data', ['summary' => ['tracked' => 0, 'ok' => 0, 'low' => 0, 'out' => 0], 'items' => []]);
    }

    public function test_never_mixes_organizations(): void
    {
        $other = Organization::factory()->create();
        $foreign = Product::factory()->for($other)->stocked(0)->create();
        $mine = Product::factory()->for($this->organization)->stocked(10)->create();

        $this->sell($mine, 5, '2026-10-01 20:00:00');
        $this->createTransaction(Customer::factory()->for($other)->create(), [[$foreign, 50, '1.00']], occurredAt: '2026-10-01 20:00:00');

        $this->getInventory()
            ->assertOk()
            ->assertJsonPath('data.items.*.product.id', [$mine->id])
            ->assertJsonPath('data.items.0.units_sold', 5);
    }

    public function test_requires_authentication_and_membership(): void
    {
        $this->getJson(self::URI)->assertUnauthorized();

        $this->actingInOrganization(User::factory()->create(), $this->organization)
            ->getJson(self::URI)
            ->assertForbidden();
    }

    private function sell(Product $product, int $quantity, string $occurredAt, TransactionStatus $status = TransactionStatus::Paid): void
    {
        $this->createTransaction($this->customer, [[$product, $quantity, '10.00']], $status, $occurredAt);
    }

    private function getInventory(): TestResponse
    {
        return $this->actingInOrganization($this->owner, $this->organization)->getJson(self::URI);
    }
}
