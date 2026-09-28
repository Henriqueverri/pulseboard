<?php

namespace Tests\Feature\Domain;

use App\Exceptions\CrossOrganizationReferenceException;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Support\CurrentOrganization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organizationA;

    private Organization $organizationB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizationA = $this->organizationWithData(products: 3, customers: 2, transactions: 4);
        $this->organizationB = $this->organizationWithData(products: 2, customers: 3, transactions: 5);
    }

    public function test_organization_relations_only_return_own_records(): void
    {
        $a = $this->organizationA;

        $this->assertTrue($a->products->every(fn (Product $p) => $p->organization_id === $a->id));
        $this->assertTrue($a->customers->every(fn (Customer $c) => $c->organization_id === $a->id));
        $this->assertTrue($a->transactions->every(fn (Transaction $t) => $t->organization_id === $a->id));

        $this->assertSame(3, $a->products()->count());
        $this->assertSame(4, $a->transactions()->count());
    }

    public function test_for_organization_scope_never_leaks_other_tenants(): void
    {
        $bId = $this->organizationB->id;

        foreach ([Product::class, Customer::class, Transaction::class] as $model) {
            $rows = $model::query()->forOrganization($this->organizationA)->get();

            $this->assertNotEmpty($rows);
            $this->assertFalse($rows->contains('organization_id', $bId), "{$model} leaked organization B rows");
        }
    }

    public function test_for_current_organization_scope_uses_resolved_context(): void
    {
        app()->instance(CurrentOrganization::class, new CurrentOrganization($this->organizationB));

        $transactions = Transaction::query()->forCurrentOrganization()->get();

        $this->assertCount(5, $transactions);
        $this->assertTrue($transactions->every(fn (Transaction $t) => $t->organization_id === $this->organizationB->id));
    }

    public function test_generated_datasets_have_no_cross_organization_associations(): void
    {
        foreach ([$this->organizationA, $this->organizationB] as $organization) {
            $transactions = $organization->transactions()->with(['customer', 'items.product'])->get();

            foreach ($transactions as $transaction) {
                $this->assertSame($organization->id, $transaction->customer->organization_id);
                $this->assertNotEmpty($transaction->items);

                foreach ($transaction->items as $item) {
                    $this->assertSame($organization->id, $item->product->organization_id);
                }
            }
        }
    }

    public function test_transaction_cannot_reference_customer_from_another_organization(): void
    {
        $foreignCustomer = $this->organizationB->customers()->first();

        try {
            Transaction::factory()->for($this->organizationA)->for($foreignCustomer)->create();
            $this->fail('Expected cross-organization customer to be rejected.');
        } catch (CrossOrganizationReferenceException) {
            $this->assertSame(0, Transaction::query()->where('customer_id', $foreignCustomer->id)
                ->where('organization_id', $this->organizationA->id)->count());
        }
    }

    public function test_existing_transaction_cannot_be_moved_to_a_foreign_customer(): void
    {
        $transaction = $this->organizationA->transactions()->first();
        $foreignCustomer = $this->organizationB->customers()->first();

        $this->expectException(CrossOrganizationReferenceException::class);

        $transaction->update(['customer_id' => $foreignCustomer->id]);
    }

    public function test_transaction_item_cannot_reference_product_from_another_organization(): void
    {
        $transaction = $this->organizationA->transactions()->first();
        $foreignProduct = $this->organizationB->products()->first();

        $this->expectException(CrossOrganizationReferenceException::class);

        TransactionItem::factory()->for($transaction)->for($foreignProduct)->create();
    }

    public function test_sku_and_email_are_unique_per_organization_only(): void
    {
        Product::factory()->for($this->organizationA)->create(['sku' => 'SHARED-001']);
        Product::factory()->for($this->organizationB)->create(['sku' => 'SHARED-001']);
        Customer::factory()->for($this->organizationA)->create(['email' => 'shared@example.com']);
        Customer::factory()->for($this->organizationB)->create(['email' => 'shared@example.com']);

        $this->assertSame(2, Product::query()->where('sku', 'SHARED-001')->count());
        $this->assertSame(2, Customer::query()->where('email', 'shared@example.com')->count());

        $this->expectException(QueryException::class);

        Product::factory()->for($this->organizationA)->create(['sku' => 'SHARED-001']);
    }

    public function test_customer_email_is_unique_within_organization(): void
    {
        Customer::factory()->for($this->organizationA)->create(['email' => 'dup@example.com']);

        $this->expectException(QueryException::class);

        Customer::factory()->for($this->organizationA)->create(['email' => 'dup@example.com']);
    }

    private function organizationWithData(int $products, int $customers, int $transactions): Organization
    {
        $organization = Organization::factory()->create();
        Product::factory()->for($organization)->count($products)->create();
        $customerModels = Customer::factory()->for($organization)->count($customers)->create();

        for ($i = 0; $i < $transactions; $i++) {
            Transaction::factory()
                ->for($organization)
                ->for($customerModels[$i % $customers])
                ->create();
        }

        return $organization;
    }
}
