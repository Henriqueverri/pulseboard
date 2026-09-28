<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Scope helpers for domain models that belong to an Organization.
 * Used by Products, Customers, Transactions in later phases.
 *
 * @mixin Model
 */
trait BelongsToOrganization
{
    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForOrganization(Builder $query, Organization|string $organization): Builder
    {
        $organizationId = $organization instanceof Organization
            ? $organization->id
            : $organization;

        return $query->where($this->qualifyColumn('organization_id'), $organizationId);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCurrentOrganization(Builder $query): Builder
    {
        $organization = CurrentOrganization::resolved()
            ?? throw new LogicException('No organization context has been resolved for this request.');

        return $query->forOrganization($organization);
    }

    /**
     * Route model binding only resolves records of the current organization,
     * so records from another tenant are indistinguishable from missing ones (404).
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return $this->resolveRouteBindingQuery($this->newQuery()->forCurrentOrganization(), $value, $field)->first();
    }
}
