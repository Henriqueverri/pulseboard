<?php

namespace Tests\Feature\Api\Realtime;

use App\Enums\StockStatus;
use App\Enums\TransactionStatus;
use App\Events\StockChanged;
use App\Events\TransactionRecorded;
use App\Events\TransactionStatusChanged;
use App\Models\Organization;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Feature\Api\Concerns\InteractsWithIngestApi;
use Tests\TestCase;

/**
 * What the integration changes is announced once, on the organization's private channel, and
 * only when something actually changed: idempotent replays and older stock updates are silent.
 */
class DomainEventsTest extends TestCase
{
    use InteractsWithIngestApi, RefreshDatabase;

    private Organization $organization;

    private string $plainTextKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        [, $this->plainTextKey] = $this->issueApiKey($this->organization);
    }

    public function test_a_new_transaction_is_announced_once(): void
    {
        Event::fake([TransactionRecorded::class]);
        $product = Product::factory()->for($this->organization)->create();
        $payload = $this->transactionPayload($product);

        $id = $this->asIntegration($this->plainTextKey)
            ->postJson('/api/v1/ingest/transactions', $payload)
            ->assertCreated()
            ->json('data.id');
        $this->asIntegration($this->plainTextKey)
            ->postJson('/api/v1/ingest/transactions', $payload)
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true');

        Event::assertDispatchedTimes(TransactionRecorded::class, 1);
        Event::assertDispatched(TransactionRecorded::class, function (TransactionRecorded $event) use ($id): bool {
            return $event->broadcastOn()->name === 'private-organization.'.$this->organization->id
                && $event->broadcastAs() === 'transaction.recorded'
                && $event->broadcastWith() === [
                    'id' => $id,
                    'status' => 'paid',
                    'total_amount' => '99.80',
                    'currency' => 'BRL',
                    'occurred_at' => '2026-10-13T20:00:00.000000Z',
                ];
        });
    }

    public function test_a_status_change_is_announced_once(): void
    {
        Event::fake([TransactionStatusChanged::class]);
        $transaction = $this->createIngestedTransaction($this->organization, 'order-7');
        $refund = ['status' => 'refunded', 'occurred_at' => now()->toIso8601String()];

        $this->asIntegration($this->plainTextKey)
            ->postJson('/api/v1/ingest/transactions/order-7/status-changes', $refund)
            ->assertCreated();
        $this->asIntegration($this->plainTextKey)
            ->postJson('/api/v1/ingest/transactions/order-7/status-changes', $refund)
            ->assertOk();

        Event::assertDispatchedTimes(TransactionStatusChanged::class, 1);
        Event::assertDispatched(TransactionStatusChanged::class, function (TransactionStatusChanged $event) use ($transaction): bool {
            $body = $event->broadcastWith();

            return $event->broadcastAs() === 'transaction.status-changed'
                && $event->organizationId === $this->organization->id
                && $body['id'] === $transaction->id
                && $body['from'] === 'paid'
                && $body['to'] === 'refunded'
                && $body['total_amount'] === '99.80'
                && $event->from === TransactionStatus::Paid;
        });
    }

    public function test_a_stock_update_carries_the_status_it_replaced(): void
    {
        Event::fake([StockChanged::class]);
        $product = Product::factory()->for($this->organization)->stocked(10, 5)->create([
            'sku' => 'BAR-HEI',
            'name' => 'Heineken Long Neck',
            'stock_updated_at' => '2026-10-13 19:00:00',
        ]);

        $this->putStock(['tracked' => true, 'quantity' => 3, 'min_quantity' => 5, 'occurred_at' => '2026-10-13T20:00:00Z'])
            ->assertOk();
        // Older than what is stored: replayed, nothing changed, nothing announced.
        $this->putStock(['tracked' => true, 'quantity' => 9, 'min_quantity' => 5, 'occurred_at' => '2026-10-13T19:30:00Z'])
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true');

        Event::assertDispatchedTimes(StockChanged::class, 1);
        Event::assertDispatched(StockChanged::class, function (StockChanged $event) use ($product): bool {
            return $event->broadcastAs() === 'stock.changed'
                && $event->previousStatus === StockStatus::Ok
                && $event->broadcastWith() === [
                    'product_id' => $product->id,
                    'sku' => 'BAR-HEI',
                    'name' => 'Heineken Long Neck',
                    'tracked' => true,
                    'quantity' => 3,
                    'min_quantity' => 5,
                    'status' => 'low',
                    'previous_status' => 'ok',
                ];
        });
    }

    public function test_turning_stock_control_on_has_no_previous_status(): void
    {
        Event::fake([StockChanged::class]);
        Product::factory()->for($this->organization)->create(['sku' => 'BAR-HEI']);

        $this->putStock(['tracked' => true, 'quantity' => 0, 'min_quantity' => 2, 'occurred_at' => '2026-10-13T20:00:00Z'])
            ->assertOk();

        Event::assertDispatched(StockChanged::class, fn (StockChanged $event): bool => $event->previousStatus === null
            && $event->status === StockStatus::Out);
    }

    public function test_a_failing_listener_never_fails_the_ingestion(): void
    {
        Event::listen(TransactionRecorded::class, function (): void {
            throw new RuntimeException('Reverb is down.');
        });
        Log::spy();
        $product = Product::factory()->for($this->organization)->create();

        $this->asIntegration($this->plainTextKey)
            ->postJson('/api/v1/ingest/transactions', $this->transactionPayload($product))
            ->assertCreated();

        $this->assertDatabaseCount('transactions', 1);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'Domain event dispatch failed.'
            && $context['event'] === 'realtime.dispatch'
            && $context['outcome'] === 'failed'
            && $context['domain_event'] === 'transaction.recorded'
            && $context['organization_id'] === $this->organization->id
            && $context['error_type'] === RuntimeException::class
            && ! str_contains(json_encode($context), 'Reverb is down'))->once();
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionPayload(Product $product): array
    {
        return [
            'external_id' => 'order-100',
            'status' => 'paid',
            'occurred_at' => '2026-10-13T17:00:00-03:00',
            'currency' => 'BRL',
            'customer' => ['external_id' => 'customer-100', 'name' => 'Ana Lima', 'email' => 'ana@example.com'],
            'items' => [['sku' => $product->sku, 'quantity' => 2, 'unit_price' => '49.90']],
            'total_amount' => '99.80',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function putStock(array $payload): TestResponse
    {
        return $this->asIntegration($this->plainTextKey)->putJson('/api/v1/ingest/products/BAR-HEI/stock', $payload);
    }
}
