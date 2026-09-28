<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\AuthorizesOrganizationResources;
use Illuminate\Auth\Access\Response;

class CustomerPolicy
{
    use AuthorizesOrganizationResources;

    public function viewAny(User $user): Response
    {
        return $this->allowMember($user);
    }

    public function view(User $user, Customer $customer): Response
    {
        return $this->allowOwnedResource($user, $customer);
    }

    public function create(User $user): Response
    {
        return $this->allowMember($user);
    }

    public function update(User $user, Customer $customer): Response
    {
        return $this->allowOwnedResource($user, $customer);
    }

    /**
     * Deleting is restricted to owners because it can permanently remove customers without transactions.
     */
    public function delete(User $user, Customer $customer): Response
    {
        return $this->allowOwnedResource($user, $customer, Organization::ROLE_OWNER);
    }
}
