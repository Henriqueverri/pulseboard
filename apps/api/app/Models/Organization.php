<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasUuids;

    public const ROLE_OWNER = 'owner';

    public const ROLE_MEMBER = 'member';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'currency',
    ];

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
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
