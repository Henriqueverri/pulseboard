<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;
use App\Policies\Concerns\AuthorizesOrganizationResources;
use Illuminate\Auth\Access\Response;

/**
 * Transactions are read-only historical records: no write abilities are defined.
 */
class TransactionPolicy
{
    use AuthorizesOrganizationResources;

    public function viewAny(User $user): Response
    {
        return $this->allowMember($user);
    }

    public function view(User $user, Transaction $transaction): Response
    {
        return $this->allowOwnedResource($user, $transaction);
    }
}
