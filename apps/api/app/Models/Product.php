<?php

namespace App\Models;

use App\Enums\ProductStatus;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'name',
        'sku',
        'price',
        'status',
        'external_id',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'status' => ProductStatus::class,
        ];
    }

    /**
     * @return HasMany<TransactionItem, $this>
     */
    public function transactionItems(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    /**
     * Case-insensitive match on name or SKU.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->whereLike($this->qualifyColumn('name'), "%{$term}%")
            ->orWhereLike($this->qualifyColumn('sku'), "%{$term}%"));
    }

    public function hasSalesHistory(): bool
    {
        return $this->transactionItems()->exists();
    }

    /**
     * Products referenced by transaction items are soft deleted so sales history
     * keeps its product; products that were never sold are removed permanently.
     */
    public function deletePreservingHistory(): void
    {
        $this->hasSalesHistory() ? $this->delete() : $this->forceDelete();
    }
}
