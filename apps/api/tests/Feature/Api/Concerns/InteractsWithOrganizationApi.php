<?php

namespace Tests\Feature\Api\Concerns;

use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;

trait InteractsWithOrganizationApi
{
    protected function memberOf(Organization $organization, string $role = Organization::ROLE_OWNER): User
    {
        $user = User::factory()->create();
        $organization->users()->attach($user->id, ['role' => $role]);

        return $user;
    }

    protected function actingInOrganization(User $user, Organization $organization): static
    {
        return $this->actingAs($user)->withHeader('X-Organization-Id', $organization->id);
    }

    /**
     * @param  array<int, array{0: Product, 1: int, 2: string}>  $lines  product, quantity, unit price
     */
    protected function createTransaction(
        Customer $customer,
        array $lines,
        TransactionStatus $status = TransactionStatus::Paid,
        ?string $occurredAt = null,
    ): Transaction {
        $transaction = Transaction::factory()
            ->for($customer->organization)
            ->for($customer)
            ->status($status)
            ->state(['occurred_at' => $occurredAt ?? now()])
            ->make();

        $transaction->save();

        foreach ($lines as [$product, $quantity, $unitPrice]) {
            TransactionItem::factory()->for($transaction)->for($product)->create([
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
            ]);
        }

        return $transaction->refresh();
    }
}
