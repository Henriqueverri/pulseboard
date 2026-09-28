<?php

namespace Tests\Feature\Domain;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_belongs_to_organization(): void
    {
        $organization = Organization::factory()->create();
        $product = Product::factory()->for($organization)->create();

        $this->assertTrue($product->organization->is($organization));
        $this->assertTrue($organization->products->contains($product));
    }

    public function test_customer_belongs_to_organization(): void
    {
        $organization = Organization::factory()->create();
        $customer = Customer::factory()->for($organization)->create();

        $this->assertTrue($customer->organization->is($organization));
        $this->assertTrue($organization->customers->contains($customer));
    }

    public function test_transaction_belongs_to_organization_and_customer(): void
    {
        $organization = Organization::factory()->create();
        $customer = Customer::factory()->for($organization)->create();

        $transaction = Transaction::factory()
            ->for($organization)
            ->for($customer)
            ->create();

        $this->assertTrue($transaction->organization->is($organization));
        $this->assertTrue($transaction->customer->is($customer));
        $this->assertTrue($organization->transactions->contains($transaction));
        $this->assertTrue($customer->transactions->contains($transaction));
    }

    public function test_transaction_has_items_that_belong_to_products(): void
    {
        $organization = Organization::factory()->create();
        [$mouse, $keyboard] = Product::factory()->for($organization)->count(2)->create();
        $transaction = Transaction::factory()->for($organization)->create();

        // The factory adds items when none are given; start from a known state.
        $transaction->items()->each(fn (TransactionItem $item) => $item->delete());

        $first = TransactionItem::factory()->for($transaction)->for($mouse)->create();
        $second = TransactionItem::factory()->for($transaction)->for($keyboard)->create();

        $items = $transaction->fresh()->items;

        $this->assertCount(2, $items);
        $this->assertTrue($first->transaction->is($transaction));
        $this->assertTrue($first->product->is($mouse));
        $this->assertTrue($second->product->is($keyboard));
        $this->assertTrue($mouse->transactionItems->contains($first));
    }

    public function test_item_keeps_its_product_after_the_product_is_soft_deleted(): void
    {
        $item = TransactionItem::factory()->create();
        $product = $item->product;

        $product->delete();

        $this->assertSoftDeleted($product);
        $this->assertTrue($item->fresh()->product->is($product));
    }
}
