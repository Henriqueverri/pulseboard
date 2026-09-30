<?php

namespace Tests\Feature;

use App\Console\Commands\DemoCommand;
use App\Enums\ProductStatus;
use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

class DemoCommandTest extends TestCase
{
    use InteractsWithOrganizationApi;
    use RefreshDatabase;

    private const PASSWORD = 'demo-password-123';

    protected function setUp(): void
    {
        parent::setUp();

        config(['demo.password' => self::PASSWORD]);
    }

    public function test_refuses_to_run_without_a_strong_enough_password(): void
    {
        foreach ([null, 'short'] as $password) {
            config(['demo.password' => $password]);

            $this->artisan('pulseboard:demo')->assertFailed();
        }

        $this->assertSame(0, Organization::query()->count());
        $this->assertSame(0, User::query()->count());
    }

    public function test_creates_the_demo_organization_with_owner_and_member_accounts(): void
    {
        $this->artisan('pulseboard:demo')->assertSuccessful();

        $organization = Organization::query()->where('slug', DemoCommand::ORGANIZATION_SLUG)->sole();
        $owner = User::query()->where('email', 'demo@example.com')->sole();
        $member = User::query()->where('email', 'demo-member@example.com')->sole();

        $this->assertSame(Organization::ROLE_OWNER, $owner->roleIn($organization));
        $this->assertSame(Organization::ROLE_MEMBER, $member->roleIn($organization));
        $this->assertSame(40, $organization->products()->count());
        $this->assertSame(70, $organization->customers()->count());
        $this->assertGreaterThan(300, $organization->transactions()->count());
        $this->assertStringEndsWith('@example.com', $organization->customers()->value('email'));

        $this->postJson('/api/v1/auth/login', ['email' => 'demo@example.com', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('organization.id', $organization->id);
    }

    public function test_running_again_keeps_the_data_and_syncs_the_password(): void
    {
        $this->artisan('pulseboard:demo')->assertSuccessful();
        $organization = Organization::query()->sole();
        $transactions = $organization->transactions()->pluck('id')->sort()->values();

        config(['demo.password' => 'a-rotated-password']);
        $this->artisan('pulseboard:demo')->assertSuccessful();

        $this->assertSame(1, Organization::query()->count());
        $this->assertSame(2, User::query()->count());
        $this->assertEquals($transactions, $organization->transactions()->pluck('id')->sort()->values());
        $this->assertTrue(Hash::check('a-rotated-password', User::query()->where('email', 'demo@example.com')->value('password')));
    }

    public function test_refresh_rebuilds_only_the_demo_organization(): void
    {
        $this->artisan('pulseboard:demo')->assertSuccessful();
        $demo = Organization::query()->sole();
        Product::factory()->for($demo)->create(['name' => 'Created by a visitor']);
        $demo->customers()->first()->delete();

        $other = Organization::factory()->create();
        $otherProduct = Product::factory()->for($other)->create();
        $otherCustomer = Customer::factory()->for($other)->create();
        $otherTransaction = $this->createTransaction($otherCustomer, [[$otherProduct, 2, '10.00']], TransactionStatus::Paid);

        $this->artisan('pulseboard:demo', ['--refresh' => true])->assertSuccessful();

        $this->assertFalse(Product::withTrashed()->where('name', 'Created by a visitor')->exists());
        $this->assertSame(40, Product::withTrashed()->forOrganization($demo)->count());
        $this->assertSame(70, Customer::withTrashed()->forOrganization($demo)->count());
        $this->assertSame(0, Customer::onlyTrashed()->count());
        $this->assertSame(2, $demo->users()->count());

        $this->assertModelExists($otherProduct);
        $this->assertModelExists($otherCustomer);
        $this->assertSame('20.00', $otherTransaction->refresh()->total_amount);
    }

    public function test_rerunning_later_extends_the_history_up_to_today(): void
    {
        $this->artisan('pulseboard:demo')->assertSuccessful();
        $organization = Organization::query()->sole();
        $seeded = $organization->transactions()->pluck('id');

        $this->travel(60)->days();
        $this->artisan('pulseboard:demo')->assertSuccessful();

        $today = DemoDataSeeder::today($organization);
        $days = $this->localSaleDays($organization);

        $this->assertSame($seeded->count(), $organization->transactions()->whereIn('id', $seeded)->count(), 'existing history is kept');
        $this->assertSame($today->toDateString(), $days->last());

        for ($daysAgo = 0; $daysAgo < 60; $daysAgo++) {
            $this->assertContains($today->subDays($daysAgo)->toDateString(), $days, "sales {$daysAgo} days ago");
        }

        $owner = User::query()->where('email', 'demo@example.com')->sole();
        $dashboard = $this->actingInOrganization($owner, $organization)->getJson('/api/v1/dashboard')->assertOk();

        $this->assertGreaterThan(0, $dashboard->json('data.orders.value'));
        $this->assertGreaterThan(0, $dashboard->json('data.orders.previous'));
        $this->assertIsFloat($dashboard->json('data.revenue.change'));
        $this->assertSame($organization->timezone, $dashboard->json('meta.timezone'));
    }

    public function test_extending_twice_on_the_same_day_adds_nothing(): void
    {
        $this->artisan('pulseboard:demo')->assertSuccessful();
        $organization = Organization::query()->sole();

        $this->travel(10)->days();
        $this->artisan('pulseboard:demo')->assertSuccessful();
        $afterFirstRun = $organization->transactions()->pluck('id')->sort()->values();

        $this->artisan('pulseboard:demo')->assertSuccessful();

        $this->assertEquals($afterFirstRun, $organization->transactions()->pluck('id')->sort()->values());
    }

    public function test_extending_from_the_same_state_generates_the_same_sales(): void
    {
        $this->artisan('pulseboard:demo')->assertSuccessful();
        $organization = Organization::query()->sole();
        $seeded = $organization->transactions()->pluck('id');

        $this->travel(20)->days();
        $this->artisan('pulseboard:demo')->assertSuccessful();
        $first = $this->salesSignature($organization, $seeded);

        Transaction::query()->forOrganization($organization)->whereNotIn('id', $seeded)->delete();
        $this->artisan('pulseboard:demo')->assertSuccessful();

        $this->assertNotEmpty($first);
        $this->assertSame($first, $this->salesSignature($organization, $seeded));
    }

    public function test_extension_only_sells_the_active_demo_catalog_to_existing_customers(): void
    {
        $this->artisan('pulseboard:demo')->assertSuccessful();
        $organization = Organization::query()->sole();
        $seeded = $organization->transactions()->pluck('id');

        $visitorProduct = Product::factory()->for($organization)->create(['sku' => 'VISITOR-1', 'price' => '10.00']);
        $deletedProduct = $organization->products()->where('status', ProductStatus::Active)->orderBy('sku')->first();
        $deletedProduct->deletePreservingHistory();
        $deletedCustomer = $organization->customers()->orderBy('email')->first();
        $deletedCustomer->deletePreservingHistory();

        $this->travel(15)->days();
        $this->artisan('pulseboard:demo')->assertSuccessful();

        $newItems = TransactionItem::query()
            ->whereHas('transaction', fn ($query) => $query->forOrganization($organization)->whereNotIn('id', $seeded))
            ->with('product')
            ->get();

        $this->assertNotEmpty($newItems);

        foreach ($newItems as $item) {
            $this->assertStringStartsWith(DemoDataSeeder::CATALOG_SKU_PREFIX, $item->product->sku);
            $this->assertSame(ProductStatus::Active, $item->product->status);
            $this->assertFalse($item->product->trashed());
            $this->assertSame($item->product->price, $item->unit_price, 'sold at the current price');
        }

        $this->assertFalse($newItems->contains('product_id', $visitorProduct->id));
        $this->assertFalse($newItems->contains('product_id', $deletedProduct->id));
        $this->assertFalse($organization->transactions()->whereNotIn('id', $seeded)->where('customer_id', $deletedCustomer->id)->exists());
    }

    public function test_extension_leaves_other_organizations_untouched(): void
    {
        $other = Organization::factory()->create();
        $otherProduct = Product::factory()->for($other)->create();
        $otherCustomer = Customer::factory()->for($other)->create();
        $otherTransaction = $this->createTransaction($otherCustomer, [[$otherProduct, 2, '10.00']], TransactionStatus::Paid);

        $this->artisan('pulseboard:demo')->assertSuccessful();

        $this->travel(30)->days();
        $this->artisan('pulseboard:demo')->assertSuccessful();

        $this->assertSame(1, $other->transactions()->count());
        $this->assertSame('20.00', $otherTransaction->refresh()->total_amount);
        $this->assertSame(1, $other->products()->count());
        $this->assertSame(1, $other->customers()->count());
    }

    public function test_extension_is_capped_to_the_history_length(): void
    {
        $this->artisan('pulseboard:demo')->assertSuccessful();
        $organization = Organization::query()->sole();
        $seededDays = $this->localSaleDays($organization);

        $this->travel(400)->days();
        $this->artisan('pulseboard:demo')->assertSuccessful();

        $addedDays = $this->localSaleDays($organization)->diff($seededDays)->values();
        $today = DemoDataSeeder::today($organization);

        $this->assertCount(DemoDataSeeder::HISTORY_DAYS, $addedDays);
        $this->assertSame($today->subDays(DemoDataSeeder::HISTORY_DAYS - 1)->toDateString(), $addedDays->first());
        $this->assertSame($today->toDateString(), $addedDays->last());
    }

    public function test_refresh_after_an_extension_rebuilds_a_history_ending_today(): void
    {
        $this->artisan('pulseboard:demo')->assertSuccessful();
        $organization = Organization::query()->sole();

        $this->travel(45)->days();
        $this->artisan('pulseboard:demo')->assertSuccessful();
        $this->artisan('pulseboard:demo', ['--refresh' => true])->assertSuccessful();

        $today = DemoDataSeeder::today($organization);
        $days = $this->localSaleDays($organization);

        $this->assertSame($today->subDays(DemoDataSeeder::HISTORY_DAYS - 1)->toDateString(), $days->first());
        $this->assertSame($today->toDateString(), $days->last());
        $this->assertSame(40, $organization->products()->count());
    }

    public function test_never_takes_over_an_account_registered_with_the_demo_email(): void
    {
        $realOrganization = Organization::factory()->create();
        $realUser = User::factory()->create(['email' => 'demo@example.com', 'password' => 'their-own-password']);
        $realOrganization->users()->attach($realUser->id, ['role' => Organization::ROLE_OWNER]);

        $this->artisan('pulseboard:demo')->assertFailed();

        $this->assertFalse(Organization::query()->where('slug', DemoCommand::ORGANIZATION_SLUG)->exists());
        $this->assertTrue(Hash::check('their-own-password', $realUser->refresh()->password));
    }

    public function test_never_reuses_an_organization_that_only_shares_the_demo_slug(): void
    {
        $impostor = Organization::factory()->create(['slug' => DemoCommand::ORGANIZATION_SLUG]);
        $product = Product::factory()->for($impostor)->create();

        $this->artisan('pulseboard:demo', ['--refresh' => true])->assertFailed();

        $this->assertModelExists($product);
        $this->assertSame(0, $impostor->users()->count());
    }

    /**
     * Distinct calendar days with sales in the organization's timezone, in order.
     *
     * @return Collection<int, string>
     */
    private function localSaleDays(Organization $organization): Collection
    {
        return $organization->transactions()->pluck('occurred_at')
            ->map(fn ($occurredAt) => CarbonImmutable::parse($occurredAt, 'UTC')->setTimezone($organization->timezone)->toDateString())
            ->unique()
            ->sort()
            ->values();
    }

    /**
     * Everything that describes the sales added after $seeded, independent of generated ids.
     *
     * @param  Collection<int, string>  $seeded
     * @return list<string>
     */
    private function salesSignature(Organization $organization, Collection $seeded): array
    {
        return $organization->transactions()
            ->whereNotIn('id', $seeded)
            ->with(['customer', 'items.product'])
            ->get()
            ->map(fn (Transaction $transaction) => implode('|', [
                $transaction->occurred_at->toIso8601String(),
                $transaction->customer->email,
                $transaction->status->value,
                $transaction->total_amount,
                $transaction->items->map(fn (TransactionItem $item) => "{$item->product->sku}x{$item->quantity}@{$item->unit_price}")->sort()->implode(','),
            ]))
            ->sort()
            ->values()
            ->all();
    }
}
