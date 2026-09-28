<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransactionItem>
 */
class TransactionItemFactory extends Factory
{
    protected $model = TransactionItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transaction_id' => Transaction::factory(),
            'product_id' => fn (array $attributes) => Product::factory()->create([
                'organization_id' => Transaction::query()
                    ->findOrFail($attributes['transaction_id'])
                    ->organization_id,
            ])->id,
            'quantity' => fake()->randomElement([1, 1, 1, 2, 2, 3]),
            'unit_price' => fn (array $attributes) => Product::withTrashed()
                ->findOrFail($attributes['product_id'])
                ->price,
        ];
    }
}
