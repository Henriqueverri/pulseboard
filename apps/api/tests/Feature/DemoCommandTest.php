<?php

namespace Tests\Feature;

use App\Console\Commands\DemoCommand;
use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
