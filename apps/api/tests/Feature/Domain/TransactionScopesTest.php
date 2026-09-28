<?php

namespace Tests\Feature\Domain;

use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Transaction;
use App\Support\Analytics\ReportingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

class TransactionScopesTest extends TestCase
{
    use InteractsWithOrganizationApi, RefreshDatabase;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::factory()->create();
        $this->customer = Customer::factory()->for($organization)->create();
        $this->product = Product::factory()->for($organization)->create();
    }

    public function test_paid_keeps_only_paid_transactions(): void
    {
        $paid = $this->transactionAt('2026-09-10 12:00:00');

        foreach ([TransactionStatus::Pending, TransactionStatus::Refunded, TransactionStatus::Canceled] as $status) {
            $this->transactionAt('2026-09-10 12:00:00', $status);
        }

        $this->assertSame([$paid->id], Transaction::query()->paid()->pluck('id')->all());
    }

    public function test_occurred_within_uses_local_day_boundaries_in_utc(): void
    {
        $period = ReportingPeriod::fromDates('2026-09-10', '2026-09-11', 'America/Sao_Paulo');

        $this->transactionAt('2026-09-10 02:59:59');
        $first = $this->transactionAt('2026-09-10 03:00:00');
        $last = $this->transactionAt('2026-09-12 02:59:59');
        $this->transactionAt('2026-09-12 03:00:00');

        $this->assertEqualsCanonicalizing(
            [$first->id, $last->id],
            Transaction::query()->occurredWithin($period)->pluck('id')->all(),
        );
    }

    public function test_scopes_compose_with_organization_scope_and_joins(): void
    {
        $period = ReportingPeriod::fromDates('2026-09-10', '2026-09-10', 'America/Sao_Paulo');
        $mine = $this->transactionAt('2026-09-10 15:00:00');
        $this->transactionAt('2026-09-10 15:00:00', TransactionStatus::Refunded);

        $foreignOrganization = Organization::factory()->create();
        $foreignCustomer = Customer::factory()->for($foreignOrganization)->create();
        $this->createTransaction($foreignCustomer, [[Product::factory()->for($foreignOrganization)->create(), 1, '10.00']], occurredAt: '2026-09-10 15:00:00');

        $ids = Transaction::query()
            ->join('customers', 'customers.id', '=', 'transactions.customer_id')
            ->forOrganization($this->customer->organization_id)
            ->paid()
            ->occurredWithin($period)
            ->pluck('transactions.id')
            ->all();

        $this->assertSame([$mine->id], $ids);
    }

    private function transactionAt(string $occurredAt, TransactionStatus $status = TransactionStatus::Paid): Transaction
    {
        return $this->createTransaction($this->customer, [[$this->product, 1, '10.00']], $status, $occurredAt);
    }
}
