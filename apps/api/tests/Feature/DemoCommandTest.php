<?php

namespace Tests\Feature;

use App\Console\Commands\DemoCommand;
use App\Enums\ProductStatus;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Models\AiRun;
use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\TransactionStatusChange;
use App\Models\User;
use App\Services\Ai\Fake\ScriptedLlmClient;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Ai\Concerns\InteractsWithInsights;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\Feature\Domain\Concerns\AssertsStatusHistory;
use Tests\TestCase;

class DemoCommandTest extends TestCase
{
    use AssertsStatusHistory;
    use InteractsWithInsights;
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

    public function test_never_creates_api_keys_on_create_extend_or_refresh(): void
    {
        $this->artisan('pulseboard:demo')->assertSuccessful();
        $this->assertSame(0, ApiKey::query()->count());

        $this->travel(30)->days();
        $this->artisan('pulseboard:demo')->assertSuccessful();
        $this->assertSame(0, ApiKey::query()->count());

        $this->artisan('pulseboard:demo', ['--refresh' => true])->assertSuccessful();
        $this->assertSame(0, ApiKey::query()->count());
    }

    public function test_refresh_removes_the_demo_keys_but_a_plain_rerun_keeps_them(): void
    {
        $this->artisan('pulseboard:demo')->assertSuccessful();
        $demo = Organization::query()->sole();
        $visitorKey = ApiKey::factory()->for($demo)->create();
        $otherKey = ApiKey::factory()->for(Organization::factory())->create();

        $this->artisan('pulseboard:demo')->assertSuccessful();
        $this->assertModelExists($visitorKey);

        $this->artisan('pulseboard:demo', ['--refresh' => true])->assertSuccessful();
        $this->assertModelMissing($visitorKey);
        $this->assertModelExists($otherKey);
    }

    public function test_refresh_removes_the_demo_accounts_tokens_but_a_plain_rerun_keeps_them(): void
    {
        $this->artisan('pulseboard:demo')->assertSuccessful();
        $owner = User::query()->where('email', 'demo@example.com')->sole();
        $member = User::query()->where('email', 'demo-member@example.com')->sole();
        $owner->createToken('Pixel 8 · a1b2', ['*'], now()->addDays(30));
        $member->createToken('Pixel 8 · c3d4', ['*'], now()->addDays(30));
        $other = $this->memberOf(Organization::factory()->create());
        $other->createToken('Pixel 8 · e5f6', ['*'], now()->addDays(30));

        $this->artisan('pulseboard:demo')->assertSuccessful();
        $this->assertSame(1, $owner->tokens()->count());
        $this->assertSame(1, $member->tokens()->count());

        $this->artisan('pulseboard:demo', ['--refresh' => true])->assertSuccessful();
        $this->assertSame(0, $owner->tokens()->count());
        $this->assertSame(0, $member->tokens()->count());
        $this->assertSame(1, $other->tokens()->count());
    }

    public function test_extended_and_refreshed_sales_keep_source_and_status_history(): void
    {
        $this->artisan('pulseboard:demo')->assertSuccessful();
        $organization = Organization::query()->sole();
        $seeded = $organization->transactions()->pluck('id');

        $this->travel(30)->days();
        $this->artisan('pulseboard:demo')->assertSuccessful();

        $this->assertGreaterThan(0, $organization->transactions()->whereNotIn('id', $seeded)->count());
        $this->assertSame(0, $organization->transactions()->where('source', '!=', TransactionSource::Seed)->count());
        $this->assertStatusHistoryMatchesCurrentStatus();

        $this->artisan('pulseboard:demo', ['--refresh' => true])->assertSuccessful();

        $this->assertSame(0, TransactionStatusChange::query()->whereIn('transaction_id', $seeded)->count());
        $this->assertStatusHistoryMatchesCurrentStatus();
        $this->assertSame(0, $organization->products()->whereNull('external_id')->count());
        $this->assertSame(0, $organization->customers()->whereNull('external_id')->count());
    }

    public function test_rerun_fills_the_external_ids_of_a_demo_seeded_before_they_existed(): void
    {
        $this->artisan('pulseboard:demo')->assertSuccessful();
        $organization = Organization::query()->sole();
        $expected = $this->externalIds($organization);
        $this->clearExternalIds($organization);

        $before = $this->demoState($organization);
        $analytics = $this->analyticsResponses($organization);

        $this->artisan('pulseboard:demo')
            ->expectsOutput('Filled the missing external ids of 110 demo products and customers.')
            ->assertSuccessful();

        $this->assertSame($expected, $this->externalIds($organization), 'same ids as a fresh seed');
        $this->assertEquals($this->withoutExternalIds($before), $this->withoutExternalIds($this->demoState($organization)), 'nothing else changed');
        $this->assertSame($analytics, $this->analyticsResponses($organization));
        $this->assertStatusHistoryMatchesCurrentStatus();
        $this->assertSame(0, ApiKey::query()->count());

        $afterBackfill = $this->demoState($organization);

        $this->artisan('pulseboard:demo')
            ->doesntExpectOutputToContain('Filled the missing external ids')
            ->assertSuccessful();

        $this->assertEquals($afterBackfill, $this->demoState($organization), 'running again changes nothing');
        $this->assertSame(0, ApiKey::query()->count());
    }

    public function test_external_id_backfill_only_touches_recognizable_seeded_records_without_an_id(): void
    {
        $this->artisan('pulseboard:demo')->assertSuccessful();
        $organization = Organization::query()->sole();
        $expected = $this->externalIds($organization);
        $this->clearExternalIds($organization);

        $customer = fn (int $number) => Customer::withTrashed()->forOrganization($organization)
            ->whereKey(array_search(DemoDataSeeder::customerExternalId($number), $expected['customers'], true))
            ->sole();

        // A visitor's customer whose email looks seeded and maps to the same id as demo customer #5.
        $lookalike = Customer::factory()->for($organization)->create([
            'email' => $this->unusedSeededLookingEmail($organization, 5),
            'external_id' => null,
        ]);

        // The id of demo customer #10 is already used by a visitor's customer.
        $holder = Customer::factory()->for($organization)->create(['external_id' => DemoDataSeeder::customerExternalId(10)]);
        // Demo customer #20 had its email changed, #30 already got an id from the UI.
        $customer(20)->update(['email' => 'renamed@corp.example']);
        $customer(30)->update(['external_id' => 'crm-30']);
        // A deleted demo product still gets its id; visitor products never do.
        $deletedProduct = $organization->products()->where('sku', 'PB-002-ESS')->sole();
        $deletedProduct->delete();
        $outOfCatalog = Product::factory()->for($organization)->create(['sku' => 'PB-099-ESS', 'external_id' => null]);
        $visitorProduct = Product::factory()->for($organization)->create(['sku' => 'VISITOR-1', 'external_id' => null]);

        $this->artisan('pulseboard:demo')->assertSuccessful();

        $this->assertSame(DemoDataSeeder::customerExternalId(5), $customer(5)->external_id, 'the seeded (older) customer wins');
        $this->assertNull($lookalike->refresh()->external_id);
        $this->assertSame(DemoDataSeeder::customerExternalId(10), $holder->refresh()->external_id);
        $this->assertNull($customer(10)->external_id);
        $this->assertNull($customer(20)->external_id);
        $this->assertSame('crm-30', $customer(30)->external_id);
        $this->assertSame('demo-prd-002-ESS', $deletedProduct->refresh()->external_id);
        $this->assertNull($outOfCatalog->refresh()->external_id);
        $this->assertNull($visitorProduct->refresh()->external_id);
        $this->assertSame(40, Product::withTrashed()->forOrganization($organization)->whereNotNull('external_id')->count());
        $this->assertSame(67 + 1, Customer::withTrashed()->forOrganization($organization)->where('external_id', 'like', 'demo-cus-%')->count());

        $state = $this->demoState($organization);
        $this->artisan('pulseboard:demo')->assertSuccessful();
        $this->assertEquals($state, $this->demoState($organization));
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

    public function test_leaves_insights_off_while_ai_is_disabled(): void
    {
        $this->artisan('pulseboard:demo')->assertSuccessful();
        $this->artisan('pulseboard:demo', ['--refresh' => true])->assertSuccessful();

        $this->assertFalse(Organization::query()->sole()->insightsEnabled());
    }

    public function test_opts_only_the_demo_organization_into_insights_while_ai_is_enabled(): void
    {
        config(['ai.enabled' => true]);
        $other = Organization::factory()->create();

        $this->artisan('pulseboard:demo')
            ->expectsOutputToContain('Insights enabled for the demo organization.')
            ->assertSuccessful();

        $demo = Organization::query()->where('slug', DemoCommand::ORGANIZATION_SLUG)->sole();
        $this->assertTrue($demo->insightsEnabled());
        $this->assertSame(User::query()->where('email', 'demo@example.com')->value('id'), $demo->ai_insights_enabled_by);
        $this->assertFalse($other->refresh()->insightsEnabled());
    }

    public function test_rerunning_with_ai_enabled_turns_the_demo_opt_in_back_on(): void
    {
        $this->artisan('pulseboard:demo')->assertSuccessful();
        $demo = Organization::query()->sole();
        $this->assertFalse($demo->insightsEnabled());

        config(['ai.enabled' => true]);
        $this->artisan('pulseboard:demo')->assertSuccessful();
        $enabledAt = $demo->refresh()->ai_insights_enabled_at;
        $this->assertNotNull($enabledAt);

        $this->travel(1)->hours();
        $this->artisan('pulseboard:demo')->assertSuccessful();
        $this->assertEquals($enabledAt, $demo->refresh()->ai_insights_enabled_at);

        $demo->disableInsights();
        $this->artisan('pulseboard:demo', ['--refresh' => true])->assertSuccessful();
        $this->assertTrue($demo->refresh()->insightsEnabled());
    }

    public function test_turning_ai_off_keeps_the_demo_opt_in_and_the_kill_switch_blocks_it(): void
    {
        config(['ai.enabled' => true]);
        $this->artisan('pulseboard:demo')->assertSuccessful();

        config(['ai.enabled' => false]);
        $this->artisan('pulseboard:demo')->assertSuccessful();

        $demo = Organization::query()->sole();
        $owner = User::query()->where('email', 'demo@example.com')->sole();
        $this->assertTrue($demo->insightsEnabled());

        $this->actingInOrganization($owner, $demo)
            ->postJson('/api/v1/insights/period-summary', ['from' => now()->subDays(29)->toDateString(), 'to' => now()->toDateString()])
            ->assertStatus(503)
            ->assertJsonPath('code', 'ai_disabled');
        $this->assertSame(0, AiRun::query()->count());
    }

    public function test_after_seeding_only_the_demo_generates_with_the_scripted_provider(): void
    {
        config(['ai.enabled' => true]);
        $provider = $this->scriptedProvider();
        $this->artisan('pulseboard:demo')->assertSuccessful();

        $demo = Organization::query()->sole();
        $owner = User::query()->where('email', 'demo@example.com')->sole();
        $period = ['from' => now()->subDays(89)->toDateString(), 'to' => now()->toDateString()];

        // Nothing queued: the scripted provider's own answer, the one the E2E job shows.
        $this->actingInOrganization($owner, $demo)
            ->postJson('/api/v1/insights/period-summary', $period)
            ->assertOk()
            ->assertJsonPath('data.headline', ScriptedLlmClient::PLACEHOLDER_TEXT)
            ->assertJsonPath('data.findings.0.destination', 'dashboard')
            ->assertJsonPath('data.findings.0.evidence.0.ref', 'kpi.revenue')
            ->assertJsonPath('meta.insight.cached', false);
        $this->assertCount(1, $provider->requests());
        $this->assertSame(1, AiRun::query()->forOrganization($demo)->where('status', AiRun::STATUS_SUCCEEDED)->count());
    }

    public function test_after_seeding_other_organizations_still_cannot_generate(): void
    {
        config(['ai.enabled' => true]);
        $provider = $this->scriptedProvider();
        $other = Organization::factory()->create();
        $otherOwner = $this->memberOf($other);
        $this->artisan('pulseboard:demo')->assertSuccessful();

        $this->actingInOrganization($otherOwner, $other)
            ->postJson('/api/v1/insights/period-summary', ['from' => now()->subDays(89)->toDateString(), 'to' => now()->toDateString()])
            ->assertForbidden()
            ->assertJsonPath('code', 'ai_not_enabled');

        $this->assertSame([], $provider->requests());
        $this->assertSame(0, AiRun::query()->count());
    }

    /**
     * @return array{products: array<string, string|null>, customers: array<string, string|null>}
     */
    private function externalIds(Organization $organization): array
    {
        return [
            'products' => Product::withTrashed()->forOrganization($organization)->orderBy('id')->pluck('external_id', 'id')->all(),
            'customers' => Customer::withTrashed()->forOrganization($organization)->orderBy('id')->pluck('external_id', 'id')->all(),
        ];
    }

    /**
     * What a demo seeded before B1 looks like after the migrations: no external ids, nothing else touched.
     */
    private function clearExternalIds(Organization $organization): void
    {
        foreach (['products', 'customers'] as $table) {
            DB::table($table)->where('organization_id', $organization->id)->update(['external_id' => null]);
        }
    }

    /**
     * Every stored column of the demo's records, so any unexpected write shows up.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function demoState(Organization $organization): array
    {
        $rows = fn (string $table, string $column = 'organization_id', ?array $ids = null) => DB::table($table)
            ->when($ids === null, fn ($query) => $query->where($column, $organization->id), fn ($query) => $query->whereIn($column, $ids))
            ->orderBy('id')
            ->get()
            ->map(fn (object $row) => (array) $row)
            ->all();

        return [
            'products' => $rows('products'),
            'customers' => $rows('customers'),
            'transactions' => $rows('transactions'),
            'transaction_items' => $rows('transaction_items', 'transaction_id', $organization->transactions()->pluck('id')->all()),
            'transaction_status_changes' => $rows('transaction_status_changes'),
            'api_keys' => $rows('api_keys'),
        ];
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $state
     * @return array<string, list<array<string, mixed>>>
     */
    private function withoutExternalIds(array $state): array
    {
        foreach (['products', 'customers'] as $table) {
            $state[$table] = array_map(fn (array $row) => array_diff_key($row, ['external_id' => true]), $state[$table]);
        }

        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    private function analyticsResponses(Organization $organization): array
    {
        // Each run re-hashes the demo password, which ends earlier sessions.
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $owner = User::query()->where('email', 'demo@example.com')->sole();
        $responses = [];

        foreach (['/api/v1/dashboard', '/api/v1/analytics/revenue?granularity=week', '/api/v1/analytics/products',
            '/api/v1/analytics/customers', '/api/v1/analytics/transactions', '/api/v1/transactions?per_page=50'] as $path) {
            $responses[$path] = $this->actingInOrganization($owner, $organization)->getJson($path)->assertOk()->json();
        }

        return $responses;
    }

    private function unusedSeededLookingEmail(Organization $organization, int $number): string
    {
        foreach ([['ana', 'almeida'], ['bruno', 'barbosa'], ['camila', 'cardoso'], ['diego', 'costa']] as [$first, $last]) {
            $email = sprintf('%s.%s.%02d@example.com', $first, $last, $number);

            if (! Customer::withTrashed()->forOrganization($organization)->where('email', $email)->exists()) {
                return $email;
            }
        }

        $this->fail('No unused seeded-looking email found.');
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
