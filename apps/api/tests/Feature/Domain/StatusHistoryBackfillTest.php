<?php

namespace Tests\Feature\Domain;

use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\TransactionStatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Domain\Concerns\AssertsStatusHistory;
use Tests\TestCase;

/**
 * Runs the status history migration against transactions that existed before it.
 */
class StatusHistoryBackfillTest extends TestCase
{
    use AssertsStatusHistory, RefreshDatabase;

    public function test_every_existing_transaction_gets_the_path_to_its_current_status(): void
    {
        $migration = $this->statusChangesMigration();
        $migration->down();
        $this->assertFalse(Schema::hasTable('transaction_status_changes'));

        $occurredAt = CarbonImmutable::parse('2026-08-10 14:30:00', 'UTC');
        $ids = [];

        foreach ([Organization::factory()->create(), Organization::factory()->create()] as $organization) {
            $customer = Customer::factory()->for($organization)->create();

            foreach (TransactionStatus::cases() as $status) {
                $ids[$organization->id][$status->value] = $this->insertLegacyTransaction($customer, $status, $occurredAt);
            }
        }

        $migration->up();

        $this->assertSame(12, TransactionStatusChange::query()->count());
        $this->assertStatusHistoryMatchesCurrentStatus();

        foreach ($ids as $organizationId => $byStatus) {
            foreach ($byStatus as $status => $transactionId) {
                $history = TransactionStatusChange::query()->where('transaction_id', $transactionId)->get();

                $this->assertSame(
                    array_map(fn (TransactionStatus $step) => $step->value, TransactionStatus::from($status)->pathFromCreation()),
                    $history->sortBy(fn (TransactionStatusChange $change) => $change->from_status === null ? 0 : 1)
                        ->map(fn (TransactionStatusChange $change) => $change->to_status->value)
                        ->values()
                        ->all(),
                );

                foreach ($history as $change) {
                    $this->assertSame($organizationId, $change->organization_id);
                    $this->assertSame(TransactionSource::Seed, $change->source);
                    $this->assertNull($change->api_key_id);
                    $this->assertTrue($change->occurred_at->equalTo($occurredAt));
                    $this->assertTrue(Str::isUuid($change->id));
                }
            }
        }

        $this->assertSame(12, TransactionStatusChange::query()->distinct()->count('id'));
    }

    public function test_backfill_of_an_empty_database_creates_nothing(): void
    {
        $migration = $this->statusChangesMigration();
        $migration->down();
        $migration->up();

        $this->assertTrue(Schema::hasTable('transaction_status_changes'));
        $this->assertSame(0, TransactionStatusChange::query()->count());
    }

    private function statusChangesMigration(): Migration
    {
        return require database_path('migrations/2026_10_04_000004_create_transaction_status_changes_table.php');
    }

    private function insertLegacyTransaction(Customer $customer, TransactionStatus $status, CarbonImmutable $occurredAt): string
    {
        $id = (string) Str::uuid();

        Transaction::query()->insert([
            'id' => $id,
            'organization_id' => $customer->organization_id,
            'customer_id' => $customer->id,
            'status' => $status->value,
            'source' => TransactionSource::Seed->value,
            'total_amount' => '10.00',
            'occurred_at' => $occurredAt->format('Y-m-d H:i:s'),
            'created_at' => $occurredAt->format('Y-m-d H:i:s'),
            'updated_at' => $occurredAt->format('Y-m-d H:i:s'),
        ]);

        return $id;
    }
}
