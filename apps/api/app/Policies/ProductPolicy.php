<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Policies\Concerns\AuthorizesOrganizationResources;
use Illuminate\Auth\Access\Response;

class ProductPolicy
{
    use AuthorizesOrganizationResources;

    public function viewAny(User $user): Response
    {
        return $this->allowMember($user);
    }

    public function view(User $user, Product $product): Response
    {
        return $this->allowOwnedResource($user, $product);
    }

    public function create(User $user): Response
    {
        return $this->allowMember($user);
    }

    public function update(User $user, Product $product): Response
    {
        return $this->allowOwnedResource($user, $product);
    }

    /**
     * Deleting is restricted to owners because it can permanently remove products without sales.
     */
    public function delete(User $user, Product $product): Response
    {
        return $this->allowOwnedResource($user, $product, Organization::ROLE_OWNER);
    }
}
