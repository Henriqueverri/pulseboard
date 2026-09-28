<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use App\Exceptions\CrossOrganizationReferenceException;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\Money;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

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

    /**
     * A UUID term matches the transaction id exactly; any other term is a
     * case-insensitive match on the customer's name or email (soft-deleted customers included).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        if (Str::isUuid($term)) {
            return $query->whereKey(Str::lower($term));
        }

        return $query->whereHas('customer', fn (Builder $customer) => $customer->where(
            fn (Builder $customer) => $customer
                ->whereLike($customer->qualifyColumn('name'), "%{$term}%")
                ->orWhereLike($customer->qualifyColumn('email'), "%{$term}%")
        ));
    }

    public function recalculateTotal(): void
    {
        $cents = $this->items()
            ->pluck('line_total')
            ->sum(fn ($lineTotal): int => Money::toCents($lineTotal));

        $this->forceFill(['total_amount' => Money::fromCents($cents)])->saveQuietly();
    }
}
