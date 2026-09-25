<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    /**
     * Any member may view the organization.
     */
    public function view(User $user, Organization $organization): bool
    {
        return $user->belongsToOrganization($organization);
    }

    /**
     * Only owners may update the organization (prepared for future PATCH).
     */
    public function update(User $user, Organization $organization): bool
    {
        return $user->roleIn($organization) === Organization::ROLE_OWNER;
    }

    /**
     * Membership is required before any organization-scoped action.
     */
    public function access(User $user, Organization $organization): bool
    {
        return $user->belongsToOrganization($organization);
    }
}
