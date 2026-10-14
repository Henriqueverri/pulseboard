<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A mobile device that receives the organization alerts of its user. Removed with the personal
 * access token it registered with, or when Expo reports the token as no longer registered.
 */
class PushDevice extends Model
{
    use HasUuids;

    public const PLATFORMS = ['android', 'ios'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'personal_access_token_id',
        'token',
        'platform',
        'last_seen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Devices of every member (owner or member) of the organization.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfMembersOf(Builder $query, Organization $organization): Builder
    {
        return $query->whereIn('user_id', $organization->users()->select('users.id'));
    }
}
