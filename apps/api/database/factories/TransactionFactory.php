<?php

namespace Database\Factories;

use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'customer_id' => fn (array $attributes) => Customer::factory()->create([
                'organization_id' => $attributes['organization_id'],
            ])->id,
            'status' => TransactionStatus::Paid,
            'source' => TransactionSource::Seed,
            'occurred_at' => fake()->dateTimeBetween('-90 days', 'now'),
        ];
    }

    /**
     * A transaction without items would have a meaningless total, so items are
     * created from the same organization's catalog unless provided via has().
     * The status history is the path from creation to the transaction's status.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Transaction $transaction): void {
            if (! $transaction->items()->exists()) {
                $this->createItemsFor($transaction);
            }

            // Items update the total through their own copy of the transaction.
            $transaction->recalculateTotal();

            if (! $transaction->statusChanges()->exists()) {
                $this->createStatusHistoryFor($transaction);
            }
        });
    }

    public function status(TransactionStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    private function createStatusHistoryFor(Transaction $transaction): void
    {
        $from = null;

        foreach ($transaction->status->pathFromCreation() as $to) {
            $transaction->statusChanges()->create([
                'organization_id' => $transaction->organization_id,
                'from_status' => $from,
                'to_status' => $to,
                'occurred_at' => $transaction->occurred_at,
                'source' => $transaction->source,
            ]);

            $from = $to;
        }
    }

    private function createItemsFor(Transaction $transaction): void
    {
        $products = Product::query()
            ->forOrganization($transaction->organization_id)
            ->inRandomOrder()
            ->limit(3)
            ->get();

        if ($products->isEmpty()) {
            $products = Product::factory()
                ->count(2)
                ->create(['organization_id' => $transaction->organization_id]);
        }

        $products->random(fake()->numberBetween(1, $products->count()))
            ->each(fn (Product $product) => TransactionItem::factory()->create([
                'transaction_id' => $transaction->id,
                'product_id' => $product->id,
            ]));
    }
}
