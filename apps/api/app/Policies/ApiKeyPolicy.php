<?php

namespace App\Policies;

use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\AuthorizesOrganizationResources;
use Illuminate\Auth\Access\Response;

/**
 * Members see the organization's keys (never their secrets); only owners issue and revoke them.
 */
class ApiKeyPolicy
{
    use AuthorizesOrganizationResources;

    public function viewAny(User $user): Response
    {
        return $this->allowMember($user);
    }

    public function create(User $user): Response
    {
        return $this->allowMember($user, Organization::ROLE_OWNER);
    }

    public function delete(User $user, ApiKey $apiKey): Response
    {
        return $this->allowOwnedResource($user, $apiKey, Organization::ROLE_OWNER);
    }
}
