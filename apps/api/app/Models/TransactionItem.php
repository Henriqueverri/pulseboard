<?php

namespace App\Models;

use App\Exceptions\CrossOrganizationReferenceException;
use App\Support\Money;
use Database\Factories\TransactionItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionItem extends Model
{
    /** @use HasFactory<TransactionItemFactory> */
    use HasFactory, HasUuids;

    /**
     * unit_price is a snapshot of the product price at purchase time.
     * line_total is always derived from quantity × unit_price.
     *
     * @var list<string>
     */
    protected $fillable = [
        'transaction_id',
        'product_id',
        'quantity',
        'unit_price',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (TransactionItem $item): void {
            $item->line_total = Money::multiply($item->unit_price, (int) $item->quantity);

            if (! $item->isDirty(['transaction_id', 'product_id'])) {
                return;
            }

            $transaction = Transaction::query()->find($item->transaction_id);
            $product = Product::withTrashed()->find($item->product_id);

            if ($transaction === null || $product === null
                || $product->organization_id !== $transaction->organization_id) {
                throw CrossOrganizationReferenceException::for('TransactionItem', 'product');
            }
        });

        static::saved(fn (TransactionItem $item) => $item->transaction->recalculateTotal());
        static::deleted(fn (TransactionItem $item) => $item->transaction->recalculateTotal());
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
