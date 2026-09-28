<?php

namespace Tests\Feature\Domain;

use App\Models\Organization;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Transaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->transaction = Transaction::factory()->for($this->organization)->create();
        $this->transaction->items()->each(fn (TransactionItem $item) => $item->delete());
    }

    public function test_line_total_is_quantity_times_unit_price(): void
    {
        $product = Product::factory()->for($this->organization)->create();

        $item = TransactionItem::factory()->for($this->transaction)->for($product)->create([
            'quantity' => 3,
            'unit_price' => '19.90',
        ]);

        // 3 × 19.90 is 59.699999… in floating point; stored value must be exact.
        $this->assertSame('59.70', $item->fresh()->line_total);
    }

    public function test_line_total_cannot_be_forced_to_an_inconsistent_value(): void
    {
        $product = Product::factory()->for($this->organization)->create();

        $item = TransactionItem::query()->forceCreate([
            'transaction_id' => $this->transaction->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => '100.00',
            'line_total' => '1.00',
        ]);

        $this->assertSame('200.00', $item->fresh()->line_total);

        $item->update(['quantity' => 4]);

        $this->assertSame('400.00', $item->fresh()->line_total);
    }

    public function test_unit_price_is_a_snapshot_independent_of_current_product_price(): void
    {
        $product = Product::factory()->for($this->organization)->create(['price' => '50.00']);

        $item = TransactionItem::factory()->for($this->transaction)->for($product)->create(['quantity' => 2]);

        $product->update(['price' => '80.00']);

        $item->refresh();
        $this->assertSame('50.00', $item->unit_price);
        $this->assertSame('100.00', $item->line_total);
    }

    public function test_total_amount_tracks_the_sum_of_items(): void
    {
        [$a, $b] = Product::factory()->for($this->organization)->count(2)->create();

        TransactionItem::factory()->for($this->transaction)->for($a)->create(['quantity' => 1, 'unit_price' => '10.10']);
        $second = TransactionItem::factory()->for($this->transaction)->for($b)->create(['quantity' => 3, 'unit_price' => '0.10']);

        $this->assertSame('10.40', $this->transaction->fresh()->total_amount);

        $second->delete();

        $this->assertSame('10.10', $this->transaction->fresh()->total_amount);
    }

    public function test_total_amount_is_not_mass_assignable(): void
    {
        $this->transaction->update(['total_amount' => '999.99']);

        $this->assertSame('0.00', $this->transaction->fresh()->total_amount);
    }

    public function test_factory_transactions_are_consistent_by_default(): void
    {
        $transactions = Transaction::factory()->for($this->organization)->count(5)->create();

        foreach ($transactions as $transaction) {
            $transaction->load(['customer', 'items.product']);

            $this->assertSame($this->organization->id, $transaction->customer->organization_id);
            $this->assertNotEmpty($transaction->items);

            $sum = 0;
            foreach ($transaction->items as $item) {
                $this->assertSame($this->organization->id, $item->product->organization_id);
                $this->assertEqualsWithDelta((float) $item->unit_price * $item->quantity, (float) $item->line_total, 0.001);
                $sum += (int) round((float) $item->line_total * 100);
            }

            $this->assertSame($sum, (int) round((float) $transaction->total_amount * 100));
            $this->assertGreaterThan(0, $sum);
        }
    }
}
