<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'name',
        'email',
        'external_id',
    ];

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Case-insensitive match on name or email.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->whereLike($this->qualifyColumn('name'), "%{$term}%")
            ->orWhereLike($this->qualifyColumn('email'), "%{$term}%"));
    }

    public function hasTransactions(): bool
    {
        return $this->transactions()->exists();
    }

    /**
     * Customers with transactions are soft deleted so transaction history keeps
     * its customer; customers without transactions are removed permanently.
     */
    public function deletePreservingHistory(): void
    {
        $this->hasTransactions() ? $this->delete() : $this->forceDelete();
    }
}
