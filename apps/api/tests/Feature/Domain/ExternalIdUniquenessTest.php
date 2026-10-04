<?php

namespace Tests\Feature\Domain;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Transaction;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * external_id is unique per organization in the database itself (not only in validation):
 * for transactions that index is the idempotency guarantee of ingestion.
 */
class ExternalIdUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->other = Organization::factory()->create();
    }

    public function test_customer_external_id_is_unique_within_an_organization_only(): void
    {
        Customer::factory()->for($this->organization)->create(['external_id' => 'cus_1']);

        $this->assertUniqueViolation(fn () => Customer::factory()->for($this->organization)->create(['external_id' => 'cus_1']));

        $elsewhere = Customer::factory()->for($this->other)->create(['external_id' => 'cus_1']);
        $this->assertSame('cus_1', $elsewhere->refresh()->external_id);
    }

    public function test_product_external_id_is_unique_within_an_organization_only(): void
    {
        Product::factory()->for($this->organization)->create(['external_id' => 'prd_1']);

        $this->assertUniqueViolation(fn () => Product::factory()->for($this->organization)->create(['external_id' => 'prd_1']));

        $elsewhere = Product::factory()->for($this->other)->create(['external_id' => 'prd_1']);
        $this->assertSame('prd_1', $elsewhere->refresh()->external_id);
    }

    public function test_transaction_external_id_is_unique_within_an_organization_only(): void
    {
        Transaction::factory()->for($this->organization)->create(['external_id' => 'order_1']);

        $this->assertUniqueViolation(fn () => Transaction::factory()->for($this->organization)->create(['external_id' => 'order_1']));

        $elsewhere = Transaction::factory()->for($this->other)->create(['external_id' => 'order_1']);
        $this->assertSame('order_1', $elsewhere->refresh()->external_id);
        $this->assertSame(1, Transaction::query()->forOrganization($this->organization)->count());
    }

    public function test_records_without_external_id_never_conflict(): void
    {
        $customer = Customer::factory()->for($this->organization)->create(['external_id' => null]);
        Customer::factory()->for($this->organization)->count(2)->create(['external_id' => null]);
        Product::factory()->for($this->organization)->count(3)->create(['external_id' => null]);
        Transaction::factory()->for($this->organization)->for($customer)->count(3)->create(['external_id' => null]);

        $this->assertSame(3, Customer::query()->forOrganization($this->organization)->whereNull('external_id')->count());
        $this->assertSame(3, Product::query()->forOrganization($this->organization)->whereNull('external_id')->count());
        $this->assertSame(3, Transaction::query()->forOrganization($this->organization)->whereNull('external_id')->count());
    }

    public function test_external_id_of_a_soft_deleted_record_stays_reserved(): void
    {
        Customer::factory()->for($this->organization)->create(['external_id' => 'cus_1'])->delete();
        Product::factory()->for($this->organization)->create(['external_id' => 'prd_1'])->delete();

        $this->assertUniqueViolation(fn () => Customer::factory()->for($this->organization)->create(['external_id' => 'cus_1']));
        $this->assertUniqueViolation(fn () => Product::factory()->for($this->organization)->create(['external_id' => 'prd_1']));
    }

    /**
     * The savepoint keeps the test's outer transaction usable after the violation (PostgreSQL aborts it otherwise).
     */
    private function assertUniqueViolation(Closure $insert): void
    {
        try {
            DB::transaction($insert);
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected a unique constraint violation.');
    }
}
