<?php

namespace Tests\Feature\Domain;

use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\TransactionStatusChange;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Domain\Concerns\AssertsStatusHistory;
use Tests\TestCase;

class TransactionStatusHistoryTest extends TestCase
{
    use AssertsStatusHistory, RefreshDatabase;

    public function test_factory_transactions_get_the_history_of_their_status(): void
    {
        $organization = Organization::factory()->create();

        foreach (TransactionStatus::cases() as $status) {
            Transaction::factory()->for($organization)->status($status)->create();
        }

        $this->assertSame(6, TransactionStatusChange::query()->count());
        $this->assertStatusHistoryMatchesCurrentStatus();
    }

    public function test_history_is_a_relation_of_the_transaction(): void
    {
        $transaction = Transaction::factory()->status(TransactionStatus::Refunded)->create();

        $changes = $transaction->statusChanges;

        $this->assertCount(2, $changes);
        $this->assertTrue($changes->every(fn (TransactionStatusChange $change) => $change->transaction->is($transaction)));
        $this->assertTrue($changes->every(fn (TransactionStatusChange $change) => $change->organization->is($transaction->organization)));
        $this->assertEqualsCanonicalizing(
            [null, TransactionStatus::Paid],
            $changes->map(fn (TransactionStatusChange $change) => $change->from_status)->all(),
        );
        $this->assertNotNull($changes->first()->created_at);
    }

    public function test_the_same_status_cannot_be_reached_twice(): void
    {
        $transaction = Transaction::factory()->status(TransactionStatus::Paid)->create();

        $this->expectException(UniqueConstraintViolationException::class);

        TransactionStatusChange::query()->create([
            'organization_id' => $transaction->organization_id,
            'transaction_id' => $transaction->id,
            'from_status' => TransactionStatus::Pending,
            'to_status' => TransactionStatus::Paid,
            'occurred_at' => now(),
            'source' => TransactionSource::Ingest,
        ]);
    }

    public function test_history_is_deleted_with_its_transaction(): void
    {
        $first = Transaction::factory()->status(TransactionStatus::Canceled)->create();
        $second = Transaction::factory()->create();

        $first->delete();

        $this->assertSame(0, TransactionStatusChange::query()->where('transaction_id', $first->id)->count());
        $this->assertSame(1, TransactionStatusChange::query()->where('transaction_id', $second->id)->count());
    }

    public function test_every_transaction_must_state_its_source(): void
    {
        $customer = Customer::factory()->create();

        $this->expectException(QueryException::class);

        DB::transaction(fn () => Transaction::query()->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $customer->organization_id,
            'customer_id' => $customer->id,
            'status' => TransactionStatus::Paid->value,
            'occurred_at' => now(),
        ]));
    }

    public function test_source_and_api_key_are_recorded_on_transactions_and_changes(): void
    {
        $organization = Organization::factory()->create();
        $apiKey = ApiKey::factory()->for($organization)->create();
        $transaction = Transaction::factory()->for($organization)->create([
            'source' => TransactionSource::Ingest,
            'api_key_id' => $apiKey->id,
            'external_id' => 'order_1',
            'ingest_fingerprint' => hash('sha256', 'payload'),
        ]);
        $transaction->statusChanges()->update(['api_key_id' => $apiKey->id]);

        $transaction->refresh();

        $this->assertSame(TransactionSource::Ingest, $transaction->source);
        $this->assertTrue($transaction->apiKey->is($apiKey));
        $this->assertTrue($apiKey->transactions->contains($transaction));
        $this->assertSame(TransactionSource::Ingest, $transaction->statusChanges->sole()->source);
        $this->assertTrue($transaction->statusChanges->sole()->apiKey->is($apiKey));
    }

    public function test_seeded_transactions_have_no_api_key(): void
    {
        $transaction = Transaction::factory()->create();

        $this->assertSame(TransactionSource::Seed, $transaction->refresh()->source);
        $this->assertNull($transaction->api_key_id);
        $this->assertNull($transaction->external_id);
        $this->assertNull($transaction->ingest_fingerprint);
    }
}
