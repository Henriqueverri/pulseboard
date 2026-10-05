<?php

namespace Tests\Feature\Api\Ingest;

use App\Enums\ProductStatus;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Concerns\InteractsWithIngestApi;
use Tests\TestCase;

class IngestTransactionTest extends TestCase
{
    use InteractsWithIngestApi, RefreshDatabase;

    public function test_it_creates_a_complete_transaction_and_a_new_customer(): void
    {
        $organization = Organization::factory()->create(['currency' => 'BRL']);
        [$apiKey, $plainTextKey] = $this->issueApiKey($organization);
        $product = Product::factory()->for($organization)->create(['sku' => 'SKU-ONE']);

        $response = $this->asIntegration($plainTextKey)
            ->postJson('/api/v1/ingest/transactions', $this->payload($product))
            ->assertCreated()
            ->assertHeader('Location', '/api/v1/ingest/transactions/order-100')
            ->assertJsonPath('data.external_id', 'order-100')
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.currency', 'BRL')
            ->assertJsonPath('data.total_amount', '99.80')
            ->assertJsonPath('data.customer.external_id', 'customer-100')
            ->assertJsonPath('data.items.0.sku', 'SKU-ONE')
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.unit_price', '49.90')
            ->assertJsonPath('data.items.0.line_total', '99.80')
            ->assertJsonPath('data.status_history.0.from_status', null)
            ->assertJsonPath('data.status_history.0.to_status', 'paid')
            ->assertJsonPath('data.status_history.0.source', 'ingest');

        $transaction = Transaction::query()->sole();
        $customer = Customer::query()->sole();

        $this->assertSame($response->json('data.id'), $transaction->id);
        $this->assertSame($organization->id, $transaction->organization_id);
        $this->assertSame($apiKey->id, $transaction->api_key_id);
        $this->assertSame(TransactionSource::Ingest, $transaction->source);
        $this->assertSame(TransactionStatus::Paid, $transaction->status);
        $this->assertSame('order-100', $transaction->external_id);
        $this->assertSame('customer-100', $customer->external_id);
        $this->assertSame(1, $transaction->items()->count());
        $this->assertSame(1, $transaction->statusChanges()->count());
        $this->assertSame(64, strlen($transaction->ingest_fingerprint));
    }

    public function test_an_existing_customer_is_used_without_being_overwritten(): void
    {
        $organization = Organization::factory()->create(['currency' => 'BRL']);
        [, $plainTextKey] = $this->issueApiKey($organization);
        $product = Product::factory()->for($organization)->create(['sku' => 'SKU-ONE']);
        $customer = Customer::factory()->for($organization)->create([
            'external_id' => 'customer-100',
            'name' => 'Canonical Name',
            'email' => 'canonical@example.com',
        ]);
        $payload = $this->payload($product);
        $payload['customer']['name'] = 'Ignored Name';
        $payload['customer']['email'] = 'ignored@example.com';

        $this->asIntegration($plainTextKey)
            ->postJson('/api/v1/ingest/transactions', $payload)
            ->assertCreated()
            ->assertJsonPath('data.customer.id', $customer->id);

        $this->assertSame('Canonical Name', $customer->refresh()->name);
        $this->assertSame('canonical@example.com', $customer->email);
        $this->assertSame(1, Customer::query()->count());
    }

    public function test_an_inactive_product_is_accepted(): void
    {
        $organization = Organization::factory()->create(['currency' => 'BRL']);
        [, $plainTextKey] = $this->issueApiKey($organization);
        $product = Product::factory()->for($organization)->create([
            'sku' => 'SKU-INACTIVE',
            'status' => ProductStatus::Inactive,
        ]);

        $this->asIntegration($plainTextKey)
            ->postJson('/api/v1/ingest/transactions', $this->payload($product))
            ->assertCreated();
    }

    public function test_pending_is_a_valid_initial_status_and_creates_the_matching_history(): void
    {
        $organization = Organization::factory()->create(['currency' => 'BRL']);
        [, $plainTextKey] = $this->issueApiKey($organization);
        $product = Product::factory()->for($organization)->create(['sku' => 'SKU-ONE']);

        $this->asIntegration($plainTextKey)
            ->postJson('/api/v1/ingest/transactions', [
                ...$this->payload($product),
                'status' => 'pending',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.status_history.0.to_status', 'pending');

        $this->assertSame(TransactionStatus::Pending, Transaction::query()->sole()->status);
    }

    public function test_unknown_foreign_and_deleted_products_are_rejected_by_item_index(): void
    {
        $organization = Organization::factory()->create(['currency' => 'BRL']);
        $other = Organization::factory()->create();
        [, $plainTextKey] = $this->issueApiKey($organization);
        $local = Product::factory()->for($organization)->create(['sku' => 'LOCAL']);
        $deleted = Product::factory()->for($organization)->create(['sku' => 'DELETED']);
        $foreign = Product::factory()->for($other)->create(['sku' => 'FOREIGN']);
        $deleted->delete();
        $payload = $this->payload($local);
        $payload['items'] = [
            ['sku' => 'MISSING', 'quantity' => 1, 'unit_price' => '10.00'],
            ['sku' => $foreign->sku, 'quantity' => 1, 'unit_price' => '10.00'],
            ['sku' => $deleted->sku, 'quantity' => 1, 'unit_price' => '10.00'],
        ];
        $payload['total_amount'] = '30.00';

        $this->asIntegration($plainTextKey)
            ->postJson('/api/v1/ingest/transactions', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['items.0.sku', 'items.1.sku', 'items.2.sku']);

        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_the_key_organization_wins_over_the_header_and_all_references_are_tenant_scoped(): void
    {
        $organization = Organization::factory()->create(['currency' => 'BRL']);
        $other = Organization::factory()->create(['currency' => 'BRL']);
        [, $plainTextKey] = $this->issueApiKey($organization);
        $ours = Product::factory()->for($organization)->create(['sku' => 'SHARED']);
        Product::factory()->for($other)->create(['sku' => 'SHARED']);

        $this->asIntegration($plainTextKey)
            ->withHeader('X-Organization-Id', $other->id)
            ->postJson('/api/v1/ingest/transactions', $this->payload($ours))
            ->assertCreated();

        $transaction = Transaction::query()->sole();
        $this->assertSame($organization->id, $transaction->organization_id);
        $this->assertSame($organization->id, $transaction->customer->organization_id);
        $this->assertSame($ours->id, $transaction->items()->sole()->product_id);
    }

    public function test_customer_email_conflict_rolls_the_entire_operation_back(): void
    {
        $organization = Organization::factory()->create(['currency' => 'BRL']);
        [, $plainTextKey] = $this->issueApiKey($organization);
        $product = Product::factory()->for($organization)->create(['sku' => 'SKU-ONE']);
        Customer::factory()->for($organization)->create([
            'external_id' => 'different-customer',
            'email' => 'new@example.com',
        ]);

        $this->asIntegration($plainTextKey)
            ->postJson('/api/v1/ingest/transactions', $this->payload($product))
            ->assertConflict()
            ->assertJsonPath('code', 'customer_email_conflict');

        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('transaction_items', 0);
        $this->assertDatabaseCount('transaction_status_changes', 0);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseMissing('customers', ['external_id' => 'customer-100']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'external id is required' => [['external_id' => null], 'external_id'],
            'status must be valid on creation' => [['status' => 'refunded'], 'status'],
            'datetime requires an offset' => [['occurred_at' => '2026-10-04T15:30:00'], 'occurred_at'],
            'quantity must be positive' => [['items' => [['sku' => 'SKU-ONE', 'quantity' => 0, 'unit_price' => '49.90']]], 'items.0.quantity'],
            'unit price must be a string' => [['items' => [['sku' => 'SKU-ONE', 'quantity' => 2, 'unit_price' => 49.90]]], 'items.0.unit_price'],
            'duplicate SKU is invalid' => [['items' => [
                ['sku' => 'SKU-ONE', 'quantity' => 1, 'unit_price' => '49.90'],
                ['sku' => 'SKU-ONE', 'quantity' => 1, 'unit_price' => '49.90'],
            ]], 'items.0.sku'],
        ];
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_payloads_return_the_existing_validation_shape(array $changes, string $field): void
    {
        $organization = Organization::factory()->create(['currency' => 'BRL']);
        [, $plainTextKey] = $this->issueApiKey($organization);
        $product = Product::factory()->for($organization)->create(['sku' => 'SKU-ONE']);
        $payload = array_replace($this->payload($product), $changes);

        $this->asIntegration($plainTextKey)
            ->postJson('/api/v1/ingest/transactions', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors($field);
    }

    public function test_currency_and_declared_total_mismatches_have_stable_codes(): void
    {
        $organization = Organization::factory()->create(['currency' => 'BRL']);
        [, $plainTextKey] = $this->issueApiKey($organization);
        $product = Product::factory()->for($organization)->create(['sku' => 'SKU-ONE']);

        $this->asIntegration($plainTextKey)
            ->postJson('/api/v1/ingest/transactions', [
                ...$this->payload($product),
                'currency' => 'USD',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'currency_mismatch');

        $this->postJson('/api/v1/ingest/transactions', [
            ...$this->payload($product),
            'total_amount' => '99.81',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'total_mismatch');

        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_a_datetime_more_than_five_minutes_in_the_future_is_rejected(): void
    {
        $organization = Organization::factory()->create(['currency' => 'BRL']);
        [, $plainTextKey] = $this->issueApiKey($organization);
        $product = Product::factory()->for($organization)->create(['sku' => 'SKU-ONE']);

        $this->asIntegration($plainTextKey)
            ->postJson('/api/v1/ingest/transactions', [
                ...$this->payload($product),
                'occurred_at' => now()->addMinutes(6)->toIso8601String(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors('occurred_at');
    }

    public function test_the_structured_log_contains_context_without_credentials_or_customer_data(): void
    {
        $organization = Organization::factory()->create(['currency' => 'BRL']);
        [$apiKey, $plainTextKey] = $this->issueApiKey($organization);
        $product = Product::factory()->for($organization)->create(['sku' => 'SKU-ONE']);
        Log::spy();

        $this->asIntegration($plainTextKey)
            ->postJson('/api/v1/ingest/transactions', $this->payload($product))
            ->assertCreated();

        Log::shouldHaveReceived('info')->once()->withArgs(
            function (string $message, array $context) use ($apiKey, $plainTextKey): bool {
                $logged = $message.json_encode($context);

                return $context['event'] === 'ingest.transaction'
                    && $context['outcome'] === 'created'
                    && $context['organization_id'] === $apiKey->organization_id
                    && $context['api_key_id'] === $apiKey->id
                    && $context['api_key_prefix'] === $apiKey->prefix
                    && $context['external_id'] === 'order-100'
                    && $context['items_count'] === 1
                    && $context['http_status'] === 201
                    && ! str_contains($logged, $plainTextKey)
                    && ! str_contains($logged, 'new@example.com')
                    && ! str_contains($logged, 'New Customer');
            },
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Product $product): array
    {
        return [
            'external_id' => 'order-100',
            'status' => 'paid',
            'occurred_at' => now()->subMinute()->toIso8601String(),
            'currency' => 'BRL',
            'customer' => [
                'external_id' => 'customer-100',
                'name' => 'New Customer',
                'email' => 'new@example.com',
            ],
            'items' => [
                ['sku' => $product->sku, 'quantity' => 2, 'unit_price' => '49.90'],
            ],
            'total_amount' => '99.80',
        ];
    }
}
