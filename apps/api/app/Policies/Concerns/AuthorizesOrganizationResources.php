<?php

namespace App\Policies\Concerns;

use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared checks for models scoped by organization_id, evaluated against the
 * organization resolved by EnsureOrganizationContext (never the raw header).
 */
trait AuthorizesOrganizationResources
{
    protected function allowMember(User $user, ?string $requiredRole = null): Response
    {
        $role = $this->roleInCurrentOrganization($user);

        if ($role === null) {
            return Response::deny('You do not have access to this organization.');
        }

        if ($requiredRole !== null && $role !== $requiredRole) {
            return Response::deny("This action requires the {$requiredRole} role.");
        }

        return Response::allow();
    }

    /**
     * Resources outside the current organization are reported as missing (404) to avoid leaking existence.
     */
    protected function allowOwnedResource(User $user, Model $resource, ?string $requiredRole = null): Response
    {
        $organization = CurrentOrganization::resolved();
        $role = $this->roleInCurrentOrganization($user);

        if ($organization === null || $role === null) {
            return Response::deny('You do not have access to this organization.');
        }

        if ($resource->getAttribute('organization_id') !== $organization->id) {
            return Response::denyAsNotFound();
        }

        if ($requiredRole !== null && $role !== $requiredRole) {
            return Response::deny("This action requires the {$requiredRole} role.");
        }

        return Response::allow();
    }

    private function roleInCurrentOrganization(User $user): ?string
    {
        $organization = CurrentOrganization::resolved();

        return $organization instanceof Organization ? $user->roleIn($organization) : null;
    }
}
