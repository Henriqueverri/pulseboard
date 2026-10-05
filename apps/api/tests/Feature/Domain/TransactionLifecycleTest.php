<?php

namespace Tests\Feature\Domain;

use App\Enums\TransactionStatus;
use App\Exceptions\IngestionException;
use App\Models\Organization;
use App\Models\TransactionStatusChange;
use App\Services\TransactionLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Feature\Api\Concerns\InteractsWithIngestApi;
use Tests\Feature\Domain\Concerns\AssertsStatusHistory;
use Tests\TestCase;

class TransactionLifecycleTest extends TestCase
{
    use AssertsStatusHistory, InteractsWithIngestApi, RefreshDatabase;

    public function test_a_full_lifecycle_keeps_the_status_and_the_history_in_sync(): void
    {
        $organization = Organization::factory()->create();
        $lifecycle = app(TransactionLifecycle::class);
        $transaction = $this->createIngestedTransaction($organization, 'order-1', null, TransactionStatus::Pending);

        foreach ([TransactionStatus::Paid, TransactionStatus::Refunded] as $to) {
            $result = $lifecycle->transition($organization, null, 'order-1', $to, now());

            $this->assertTrue($result->applied);
            $this->assertSame($to, $result->transaction->status);
        }

        $this->assertSame(TransactionStatus::Refunded, $transaction->refresh()->status);
        $this->assertSame(3, $transaction->statusChanges()->count());
        $this->assertStatusHistoryMatchesCurrentStatus();
    }

    /**
     * The lifecycle is the only writer of the status, so a failure while recording
     * the history must take the status update with it.
     */
    public function test_a_failure_while_recording_the_history_rolls_the_status_back(): void
    {
        $organization = Organization::factory()->create();
        $transaction = $this->createIngestedTransaction($organization, 'order-1', null, TransactionStatus::Pending);

        TransactionStatusChange::creating(fn () => throw new RuntimeException('Recording the history failed.'));

        try {
            app(TransactionLifecycle::class)->transition($organization, null, 'order-1', TransactionStatus::Paid, now());
            $this->fail('The transition should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Recording the history failed.', $exception->getMessage());
        } finally {
            TransactionStatusChange::flushEventListeners();
        }

        $this->assertSame(TransactionStatus::Pending, $transaction->refresh()->status);
        $this->assertSame(1, $transaction->statusChanges()->count());
        $this->assertStatusHistoryMatchesCurrentStatus();
    }

    public function test_a_rejected_transition_leaves_no_trace(): void
    {
        $organization = Organization::factory()->create();
        $transaction = $this->createIngestedTransaction($organization, 'order-1', null, TransactionStatus::Canceled);

        try {
            app(TransactionLifecycle::class)->transition($organization, null, 'order-1', TransactionStatus::Paid, now());
            $this->fail('The transition should have been rejected.');
        } catch (IngestionException $exception) {
            $this->assertSame('invalid_transition', $exception->errorCode);
        }

        $this->assertSame(TransactionStatus::Canceled, $transaction->refresh()->status);
        $this->assertSame(2, $transaction->statusChanges()->count());
        $this->assertStatusHistoryMatchesCurrentStatus();
    }

    public function test_a_transaction_of_another_organization_is_not_found(): void
    {
        $owner = Organization::factory()->create();
        $other = Organization::factory()->create();
        $this->createIngestedTransaction($owner, 'order-1', null, TransactionStatus::Pending);

        $this->expectException(IngestionException::class);

        app(TransactionLifecycle::class)->transition($other, null, 'order-1', TransactionStatus::Paid, now());
    }
}
