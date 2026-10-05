<?php

namespace Tests\Feature\Api\Ingest;

use App\Enums\TransactionStatus;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Concerns\InteractsWithIngestApi;
use Tests\TestCase;

class IngestStatusChangeTest extends TestCase
{
    use InteractsWithIngestApi, RefreshDatabase;

    /**
     * @return array<string, array{0: TransactionStatus, 1: TransactionStatus}>
     */
    public static function allowedTransitions(): array
    {
        return [
            'pending to paid' => [TransactionStatus::Pending, TransactionStatus::Paid],
            'pending to canceled' => [TransactionStatus::Pending, TransactionStatus::Canceled],
            'paid to refunded' => [TransactionStatus::Paid, TransactionStatus::Refunded],
        ];
    }

    /**
     * @return array<string, array{0: TransactionStatus, 1: TransactionStatus}>
     */
    public static function rejectedTransitions(): array
    {
        return [
            'pending to refunded' => [TransactionStatus::Pending, TransactionStatus::Refunded],
            'paid to canceled' => [TransactionStatus::Paid, TransactionStatus::Canceled],
            'paid to pending' => [TransactionStatus::Paid, TransactionStatus::Pending],
            'refunded to paid' => [TransactionStatus::Refunded, TransactionStatus::Paid],
            'refunded to canceled' => [TransactionStatus::Refunded, TransactionStatus::Canceled],
            'refunded to pending' => [TransactionStatus::Refunded, TransactionStatus::Pending],
            'canceled to paid' => [TransactionStatus::Canceled, TransactionStatus::Paid],
            'canceled to refunded' => [TransactionStatus::Canceled, TransactionStatus::Refunded],
            'canceled to pending' => [TransactionStatus::Canceled, TransactionStatus::Pending],
        ];
    }

    #[DataProvider('allowedTransitions')]
    public function test_an_allowed_transition_is_applied_and_recorded(TransactionStatus $from, TransactionStatus $to): void
    {
        $organization = Organization::factory()->create(['currency' => 'BRL']);
        [$apiKey, $plainTextKey] = $this->issueApiKey($organization);
        $transaction = $this->createIngestedTransaction($organization, 'order-1', $apiKey, $from);
        $before = $transaction->statusChanges()->count();

        $this->asIntegration($plainTextKey)
            ->postJson('/api/v1/ingest/transactions/order-1/status-changes', $this->payload($to))
            ->assertCreated()
            ->assertHeaderMissing('Idempotent-Replayed')
            ->assertJsonPath('data.id', $transaction->id)
            ->assertJsonPath('data.external_id', 'order-1')
            ->assertJsonPath('data.status', $to->value)
            ->assertJsonPath('data.total_amount', '99.80');

        $transaction->refresh();
        // Unique per transaction, so it identifies the change the request produced.
        $change = $transaction->statusChanges()->where('to_status', $to)->sole();

        $this->assertSame($to, $transaction->status);
        $this->assertSame($before + 1, $transaction->statusChanges()->count());
        $this->assertSame($from, $change->from_status);
        $this->assertSame($to, $change->to_status);
        $this->assertSame($organization->id, $change->organization_id);
        $this->assertSame($apiKey->id, $change->api_key_id);
        $this->assertSame($transaction->source, $change->source);
    }

    #[DataProvider('allowedTransitions')]
    public function test_the_response_history_is_the_chain_ending_at_the_new_status(TransactionStatus $from, TransactionStatus $to): void
    {
        $organization = Organization::factory()->create();
        [, $plainTextKey] = $this->issueApiKey($organization);
        $this->createIngestedTransaction($organization, 'order-1', null, $from);

        $this->asIntegration($plainTextKey)
            ->postJson('/api/v1/ingest/transactions/order-1/status-changes', $this->payload($to))
            ->assertCreated()
            ->assertJsonPath('data.status_history.*.to_status', [...array_map(
                fn (TransactionStatus $status): string => $status->value,
                $from->pathFromCreation(),
            ), $to->value]);
    }

    #[DataProvider('rejectedTransitions')]
    public function test_a_rejected_transition_is_a_409_that_changes_nothing(TransactionStatus $from, TransactionStatus $to): void
    {
        $organization = Organization::factory()->create();
        [, $plainTextKey] = $this->issueApiKey($organization);
        $transaction = $this->createIngestedTransaction($organization, 'order-1', null, $from);
        $before = $transaction->statusChanges()->count();

        $this->asIntegration($plainTextKey)
            ->postJson('/api/v1/ingest/transactions/order-1/status-changes', $this->payload($to))
            ->assertConflict()
            ->assertJsonPath('code', 'invalid_transition');

        $this->assertSame($from, $transaction->refresh()->status);
        $this->assertSame($before, $transaction->statusChanges()->count());
    }

    /**
     * A retry of a transition that already landed: the state is the one asked for,
     * so it is a 200 and not a second change.
     */
    public function test_asking_for_the_current_status_replays_instead_of_transitioning(): void
    {
        $organization = Organization::factory()->create();
        [, $plainTextKey] = $this->issueApiKey($organization);
        $transaction = $this->createIngestedTransaction($organization, 'order-1', null, TransactionStatus::Paid);
        $before = $transaction->statusChanges()->count();

        $this->asIntegration($plainTextKey)
            ->postJson('/api/v1/ingest/transactions/order-1/status-changes', $this->payload(TransactionStatus::Paid))
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.status', 'paid');

        $this->assertSame(TransactionStatus::Paid, $transaction->refresh()->status);
        $this->assertSame($before, $transaction->statusChanges()->count());
    }

    public function test_a_transition_reported_before_the_previous_change_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        [, $plainTextKey] = $this->issueApiKey($organization);
        $transaction = $this->createIngestedTransaction($organization, 'order-1', null, TransactionStatus::Pending);
        $transaction->statusChanges()->update(['occurred_at' => now()->subHour()]);

        $this->asIntegration($plainTextKey)
            ->postJson('/api/v1/ingest/transactions/order-1/status-changes', [
                'status' => 'paid',
                'occurred_at' => now()->subDay()->toIso8601String(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors('occurred_at');

        $this->assertSame(TransactionStatus::Pending, $transaction->refresh()->status);
        $this->assertSame(1, $transaction->statusChanges()->count());
    }

    public function test_an_unknown_external_id_is_a_404(): void
    {
        $organization = Organization::factory()->create();
        [, $plainTextKey] = $this->issueApiKey($organization);

        $this->asIntegration($plainTextKey)
            ->postJson('/api/v1/ingest/transactions/does-not-exist/status-changes', $this->payload(TransactionStatus::Paid))
            ->assertNotFound()
            ->assertExactJson(['message' => 'Transaction not found.', 'code' => 'not_found']);
    }

    /**
     * The organization comes from the key alone, so another organization's
     * transaction is indistinguishable from a missing one.
     */
    public function test_a_key_cannot_change_the_status_of_another_organizations_transaction(): void
    {
        $owner = Organization::factory()->create();
        $other = Organization::factory()->create();
        [, $ownerKey] = $this->issueApiKey($owner);
        [, $otherKey] = $this->issueApiKey($other);
        $transaction = $this->createIngestedTransaction($owner, 'order-1', null, TransactionStatus::Pending);

        $this->asIntegration($otherKey)
            ->postJson('/api/v1/ingest/transactions/order-1/status-changes', $this->payload(TransactionStatus::Paid))
            ->assertNotFound()
            ->assertExactJson(['message' => 'Transaction not found.', 'code' => 'not_found']);

        $this->assertSame(TransactionStatus::Pending, $transaction->refresh()->status);
        $this->assertSame(1, $transaction->statusChanges()->count());

        $this->asIntegration($ownerKey)
            ->postJson('/api/v1/ingest/transactions/order-1/status-changes', $this->payload(TransactionStatus::Paid))
            ->assertCreated();

        $this->assertSame(TransactionStatus::Paid, $transaction->refresh()->status);
    }

    public function test_an_organization_id_header_cannot_switch_the_tenant(): void
    {
        $owner = Organization::factory()->create();
        $other = Organization::factory()->create();
        [, $otherKey] = $this->issueApiKey($other);
        $transaction = $this->createIngestedTransaction($owner, 'order-1', null, TransactionStatus::Pending);

        $this->asIntegration($otherKey)
            ->withHeader('X-Organization-Id', $owner->id)
            ->postJson('/api/v1/ingest/transactions/order-1/status-changes', $this->payload(TransactionStatus::Paid))
            ->assertNotFound();

        $this->assertSame(TransactionStatus::Pending, $transaction->refresh()->status);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'missing status' => [['occurred_at' => '2026-10-04T12:00:00-03:00'], 'status'],
            'unknown status' => [['status' => 'disputed', 'occurred_at' => '2026-10-04T12:00:00-03:00'], 'status'],
            'status is not a string' => [['status' => ['paid'], 'occurred_at' => '2026-10-04T12:00:00-03:00'], 'status'],
            'empty status' => [['status' => '', 'occurred_at' => '2026-10-04T12:00:00-03:00'], 'status'],
            'missing occurred_at' => [['status' => 'paid'], 'occurred_at'],
            'occurred_at without offset' => [['status' => 'paid', 'occurred_at' => '2026-10-04T12:00:00'], 'occurred_at'],
            'occurred_at is not a string' => [['status' => 'paid', 'occurred_at' => 1_759_600_000], 'occurred_at'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidPayloads')]
    public function test_an_invalid_payload_is_a_422(array $payload, string $field): void
    {
        $organization = Organization::factory()->create();
        [, $plainTextKey] = $this->issueApiKey($organization);
        $transaction = $this->createIngestedTransaction($organization, 'order-1', null, TransactionStatus::Pending);

        $this->asIntegration($plainTextKey)
            ->postJson('/api/v1/ingest/transactions/order-1/status-changes', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors($field);

        $this->assertSame(TransactionStatus::Pending, $transaction->refresh()->status);
    }

    public function test_an_occurred_at_too_far_in_the_future_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        [, $plainTextKey] = $this->issueApiKey($organization);
        $this->createIngestedTransaction($organization, 'order-1', null, TransactionStatus::Pending);

        $this->asIntegration($plainTextKey)
            ->postJson('/api/v1/ingest/transactions/order-1/status-changes', [
                'status' => 'paid',
                'occurred_at' => now()->addMinutes(6)->toIso8601String(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('occurred_at');
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function unusableKeys(): array
    {
        return [
            'no key at all' => [null],
            'malformed key' => ['not-a-pulseboard-key'],
            'unknown key' => ['pb_AbCdEfGhIjKlMnOp_'.str_repeat('x', 43)],
        ];
    }

    #[DataProvider('unusableKeys')]
    public function test_the_endpoint_requires_a_usable_api_key(?string $plainTextKey): void
    {
        $organization = Organization::factory()->create();
        $transaction = $this->createIngestedTransaction($organization, 'order-1', null, TransactionStatus::Pending);

        $this->asIntegration($plainTextKey)
            ->postJson('/api/v1/ingest/transactions/order-1/status-changes', $this->payload(TransactionStatus::Paid))
            ->assertUnauthorized()
            ->assertJsonPath('code', 'invalid_api_key');

        $this->assertSame(TransactionStatus::Pending, $transaction->refresh()->status);
    }

    public function test_a_revoked_key_cannot_change_a_status(): void
    {
        $organization = Organization::factory()->create();
        [, $plainTextKey] = $this->issueApiKey($organization, ['revoked_at' => now()->subMinute()]);
        $transaction = $this->createIngestedTransaction($organization, 'order-1', null, TransactionStatus::Pending);

        $this->asIntegration($plainTextKey)
            ->postJson('/api/v1/ingest/transactions/order-1/status-changes', $this->payload(TransactionStatus::Paid))
            ->assertUnauthorized();

        $this->assertSame(TransactionStatus::Pending, $transaction->refresh()->status);
    }

    public function test_the_structured_log_describes_the_transition_without_credentials(): void
    {
        $organization = Organization::factory()->create();
        [$apiKey, $plainTextKey] = $this->issueApiKey($organization);
        $this->createIngestedTransaction($organization, 'order-1', $apiKey, TransactionStatus::Pending);
        Log::spy();

        $this->asIntegration($plainTextKey)
            ->postJson('/api/v1/ingest/transactions/order-1/status-changes', $this->payload(TransactionStatus::Paid))
            ->assertCreated();

        Log::shouldHaveReceived('info')->once()->withArgs(
            fn (string $message, array $context): bool => $context['event'] === 'ingest.status_change'
                && $context['outcome'] === 'transitioned'
                && $context['organization_id'] === $organization->id
                && $context['api_key_id'] === $apiKey->id
                && $context['api_key_prefix'] === $apiKey->prefix
                && $context['external_id'] === 'order-1'
                && $context['requested_status'] === 'paid'
                && $context['http_status'] === 201
                && ! str_contains($message.json_encode($context), $plainTextKey),
        );
    }

    /**
     * @return array<string, string>
     */
    private function payload(TransactionStatus $status): array
    {
        return [
            'status' => $status->value,
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}
