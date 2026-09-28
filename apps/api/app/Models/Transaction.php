<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use App\Exceptions\CrossOrganizationReferenceException;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\Money;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    /**
     * total_amount is derived from items (see recalculateTotal) and is not mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'customer_id',
        'status',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TransactionStatus::class,
            'total_amount' => 'decimal:2',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Transaction $transaction): void {
            if (! $transaction->isDirty(['organization_id', 'customer_id'])) {
                return;
            }

            $customer = Customer::withTrashed()->find($transaction->customer_id);

            if ($customer === null || $customer->organization_id !== $transaction->organization_id) {
                throw CrossOrganizationReferenceException::for('Transaction', 'customer');
            }
        });
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return HasMany<TransactionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    public function recalculateTotal(): void
    {
        $cents = $this->items()
            ->pluck('line_total')
            ->sum(fn ($lineTotal): int => Money::toCents($lineTotal));

        $this->forceFill(['total_amount' => Money::fromCents($cents)])->saveQuietly();
    }
}
