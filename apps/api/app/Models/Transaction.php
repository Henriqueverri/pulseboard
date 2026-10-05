<?php

namespace App\Models;

use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Exceptions\CrossOrganizationReferenceException;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\Analytics\ReportingPeriod;
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
            'source' => TransactionSource::class,
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
     * status is the denormalized current status: it always equals the to_status
     * of the last change in this history.
     *
     * @return HasMany<TransactionStatusChange, $this>
     */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(TransactionStatusChange::class);
    }

    /**
     * The loaded statusChanges in lifecycle order. Several changes may share
     * occurred_at (and created_at), so the order follows the chain from creation
     * (from_status null) instead of timestamps.
     *
     * @return list<TransactionStatusChange>
     */
    public function statusHistory(): array
    {
        $byFromStatus = $this->statusChanges->keyBy(fn (TransactionStatusChange $change) => $change->from_status->value ?? '');
        $history = [];
        $from = '';

        while (count($history) < $byFromStatus->count() && ($change = $byFromStatus->get($from)) !== null) {
            $history[] = $change;
            $from = $change->to_status->value;
        }

        return $history;
    }

    /**
     * The API key that ingested this transaction (null for seeded transactions).
     *
     * @return BelongsTo<ApiKey, $this>
     */
    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    /**
     * The single definition of a sale: financial and commercial metrics
     * (revenue, orders, units sold, average order value, total spent) count only paid transactions.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePaid(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), TransactionStatus::Paid->value);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOccurredWithin(Builder $query, ReportingPeriod $period): Builder
    {
        return $query
            ->where($this->qualifyColumn('occurred_at'), '>=', $period->startUtc())
            ->where($this->qualifyColumn('occurred_at'), '<', $period->endUtc());
    }

    /**
     * Every term matches the external_id exactly. A UUID term also matches the
     * transaction id; any other term also matches the customer's name or email,
     * case-insensitively (soft-deleted customers included).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $query) use ($term): void {
            $query->where($this->qualifyColumn('external_id'), $term);

            if (Str::isUuid($term)) {
                $query->orWhere($this->qualifyColumn('id'), Str::lower($term));

                return;
            }

            $query->orWhereHas('customer', fn (Builder $customer) => $customer->where(
                fn (Builder $customer) => $customer
                    ->whereLike($customer->qualifyColumn('name'), "%{$term}%")
                    ->orWhereLike($customer->qualifyColumn('email'), "%{$term}%")
            ));
        });
    }

    public function recalculateTotal(): void
    {
        $cents = $this->items()
            ->pluck('line_total')
            ->sum(fn ($lineTotal): int => Money::toCents($lineTotal));

        $this->forceFill(['total_amount' => Money::fromCents($cents)])->saveQuietly();
    }
}
