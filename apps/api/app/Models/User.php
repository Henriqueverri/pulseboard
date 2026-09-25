<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return BelongsToMany<Organization, $this>
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function belongsToOrganization(Organization|string $organization): bool
    {
        $organizationId = $organization instanceof Organization
            ? $organization->id
            : $organization;

        return $this->organizations()
            ->where('organizations.id', $organizationId)
            ->exists();
    }

    public function roleIn(Organization|string $organization): ?string
    {
        $organizationId = $organization instanceof Organization
            ? $organization->id
            : $organization;

        $membership = $this->organizations()
            ->where('organizations.id', $organizationId)
            ->first();

        return $membership?->pivot?->role;
    }
}
