<?php

namespace App\Models;

use App\Console\Commands\DemoCommand;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasUuids;

    public const ROLE_OWNER = 'owner';

    public const ROLE_MEMBER = 'member';

    public const DEFAULT_TIMEZONE = 'America/Sao_Paulo';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'currency',
        'timezone',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'timezone' => self::DEFAULT_TIMEZONE,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ai_insights_enabled_at' => 'datetime',
        ];
    }

    /**
     * Whether an owner opted this organization into PulseBoard Insights.
     * AI_ENABLED (config ai.enabled) is checked separately by AiUsageGuard.
     */
    public function insightsEnabled(): bool
    {
        return $this->ai_insights_enabled_at !== null;
    }

    /**
     * Idempotent: enabling again keeps who enabled it first and when.
     */
    public function enableInsights(?User $by): void
    {
        if ($this->insightsEnabled()) {
            return;
        }

        $this->forceFill([
            'ai_insights_enabled_at' => now(),
            'ai_insights_enabled_by' => $by?->id,
        ])->save();
    }

    public function disableInsights(): void
    {
        $this->forceFill([
            'ai_insights_enabled_at' => null,
            'ai_insights_enabled_by' => null,
        ])->save();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return HasMany<ApiKey, $this>
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    /**
     * The public demo organization created by pulseboard:demo.
     */
    public function isDemo(): bool
    {
        return $this->slug === DemoCommand::ORGANIZATION_SLUG;
    }

    public static function uniqueSlugFrom(string $name): string
    {
        $base = Str::slug($name) ?: 'organization';
        $slug = $base;
        $suffix = 1;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
