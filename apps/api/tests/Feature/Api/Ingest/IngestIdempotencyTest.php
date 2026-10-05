<?php

namespace Tests\Feature\Api\Ingest;

use App\Models\Organization;
use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\Concerns\InteractsWithIngestApi;
use Tests\TestCase;

class IngestIdempotencyTest extends TestCase
{
    use InteractsWithIngestApi, RefreshDatabase;

    public function test_an_identical_retry_returns_the_same_transaction_with_200(): void
    {
        $organization = Organization::factory()->create(['currency' => 'BRL']);
        [, $plainTextKey] = $this->issueApiKey($organization);
        $product = Product::factory()->for($organization)->create(['sku' => 'SKU-ONE']);
        $payload = $this->payload([$product]);

        $created = $this->asIntegration($plainTextKey)
            ->postJson('/api/v1/ingest/transactions', $payload)
            ->assertCreated();

        $this->postJson('/api/v1/ingest/transactions', $payload)
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $created->json('data.id'));

        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('transaction_items', 1);
        $this->assertDatabaseCount('transaction_status_changes', 1);
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_item_order_and_customer_profile_fields_do_not_change_the_fingerprint(): void
    {
        $organization = Organization::factory()->create(['currency' => 'BRL']);
        [, $plainTextKey] = $this->issueApiKey($organization);
        $first = Product::factory()->for($organization)->create(['sku' => 'SKU-A']);
        $second = Product::factory()->for($organization)->create(['sku' => 'SKU-B']);
        $payload = $this->payload([$first, $second]);

        $this->asIntegration($plainTextKey)
            ->postJson('/api/v1/ingest/transactions', $payload)
            ->assertCreated();

        $payload['items'] = array_reverse($payload['items']);
        $payload['customer']['name'] = 'A different ignored name';
        $payload['customer']['email'] = 'different@example.com';

        $this->postJson('/api/v1/ingest/transactions', $payload)
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true');

        $this->assertDatabaseCount('transactions', 1);
        $this->assertSame('Customer Name', Transaction::query()->sole()->customer->name);
    }

    public function test_the_same_external_id_with_a_different_payload_is_a_conflict(): void
    {
        $organization = Organization::factory()->create(['currency' => 'BRL']);
        [, $plainTextKey] = $this->issueApiKey($organization);
        $product = Product::factory()->for($organization)->create(['sku' => 'SKU-ONE']);
        $payload = $this->payload([$product]);

        $this->asIntegration($plainTextKey)
            ->postJson('/api/v1/ingest/transactions', $payload)
            ->assertCreated();

        $payload['items'][0]['quantity'] = 2;
        $payload['total_amount'] = '20.00';

        $this->postJson('/api/v1/ingest/transactions', $payload)
            ->assertConflict()
            ->assertJsonPath('code', 'transaction_conflict');

        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('transaction_items', 1);
    }

    public function test_external_id_uniqueness_is_scoped_to_the_organization(): void
    {
        $firstOrganization = Organization::factory()->create(['currency' => 'BRL']);
        $secondOrganization = Organization::factory()->create(['currency' => 'BRL']);
        [, $firstKey] = $this->issueApiKey($firstOrganization);
        [, $secondKey] = $this->issueApiKey($secondOrganization);
        $firstProduct = Product::factory()->for($firstOrganization)->create(['sku' => 'SHARED']);
        $secondProduct = Product::factory()->for($secondOrganization)->create(['sku' => 'SHARED']);

        $this->asIntegration($firstKey)
            ->postJson('/api/v1/ingest/transactions', $this->payload([$firstProduct]))
            ->assertCreated();

        $this->asIntegration($secondKey)
            ->postJson('/api/v1/ingest/transactions', $this->payload([$secondProduct]))
            ->assertCreated();

        $this->assertDatabaseCount('transactions', 2);
        $this->assertSame(2, Transaction::query()->where('external_id', 'order-idempotent')->count());
    }

    public function test_creation_does_not_select_the_transaction_before_trying_the_insert(): void
    {
        $organization = Organization::factory()->create(['currency' => 'BRL']);
        [, $plainTextKey] = $this->issueApiKey($organization);
        $product = Product::factory()->for($organization)->create(['sku' => 'SKU-ONE']);
        $statements = [];

        DB::listen(function ($query) use (&$statements): void {
            if (str_contains(strtolower($query->sql), 'transactions')) {
                $statements[] = strtolower($query->sql);
            }
        });

        $this->asIntegration($plainTextKey)
            ->postJson('/api/v1/ingest/transactions', $this->payload([$product]))
            ->assertCreated();

        $this->assertNotEmpty($statements);
        $this->assertStringStartsWith('insert into "transactions"', $statements[0]);
    }

    /**
     * @param  list<Product>  $products
     * @return array<string, mixed>
     */
    private function payload(array $products): array
    {
        return [
            'external_id' => 'order-idempotent',
            'status' => 'paid',
            'occurred_at' => '2026-10-04T15:30:00-03:00',
            'currency' => 'BRL',
            'customer' => [
                'external_id' => 'customer-idempotent',
                'name' => 'Customer Name',
                'email' => 'customer@example.com',
            ],
            'items' => array_map(
                fn (Product $product): array => [
                    'sku' => $product->sku,
                    'quantity' => 1,
                    'unit_price' => '10.00',
                ],
                $products,
            ),
            'total_amount' => number_format(count($products) * 10, 2, '.', ''),
        ];
    }
}
