<?php

namespace Tests\Feature\Api\Ingest;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Concerns\AssertsIngestionInvariants;
use Tests\Feature\Api\Concerns\InteractsWithIngestApi;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

/**
 * The key of organization A against every resource and aggregate of
 * organization B, with and without B's id in X-Organization-Id. Proven on the
 * persisted rows: B's data never changes, A never references B's customers or
 * products, and B's numbers never include A's sales.
 *
 * The concurrent version of this matrix lives in IngestConcurrencyTest (PostgreSQL).
 */
class IngestTenantIsolationTest extends TestCase
{
    use AssertsIngestionInvariants, InteractsWithIngestApi, InteractsWithOrganizationApi, RefreshDatabase;

    private Organization $first;

    private Organization $second;

    private string $firstKey;

    private User $secondOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->first = Organization::factory()->create(['currency' => 'BRL']);
        $this->second = Organization::factory()->create(['currency' => 'BRL']);
        $this->secondOwner = $this->memberOf($this->second);
        [, $this->firstKey] = $this->issueApiKey($this->first);
        [, $secondKey] = $this->issueApiKey($this->second);

        Product::factory()->for($this->first)->create(['sku' => 'SHARED']);
        Product::factory()->for($this->second)->create(['sku' => 'SHARED']);
        Product::factory()->for($this->second)->create(['sku' => 'ONLY-SECOND']);

        // B's own pending sale, created through its own key.
        $this->asIntegration($secondKey)
            ->postJson('/api/v1/ingest/transactions', [
                ...$this->payload('order-second', 'customer-second', 'ONLY-SECOND'),
                'status' => 'pending',
            ])
            ->assertCreated();
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function organizationHeader(): array
    {
        return [
            'without X-Organization-Id' => [false],
            'claiming B in X-Organization-Id' => [true],
        ];
    }

    #[DataProvider('organizationHeader')]
    public function test_a_cannot_read_bs_transaction(bool $claimSecond): void
    {
        $before = $this->secondSnapshot();

        $this->asFirst($claimSecond)
            ->getJson('/api/v1/ingest/transactions/order-second')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Transaction not found.', 'code' => 'not_found']);

        $this->assertSame($before, $this->secondSnapshot());
    }

    #[DataProvider('organizationHeader')]
    public function test_a_cannot_change_the_status_of_bs_transaction(bool $claimSecond): void
    {
        $before = $this->secondSnapshot();

        $this->asFirst($claimSecond)
            ->postJson('/api/v1/ingest/transactions/order-second/status-changes', [
                'status' => 'paid',
                'occurred_at' => now()->toIso8601String(),
            ])
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');

        $this->assertSame($before, $this->secondSnapshot());
    }

    #[DataProvider('organizationHeader')]
    public function test_a_cannot_sell_a_product_that_only_b_has(bool $claimSecond): void
    {
        $before = $this->secondSnapshot();

        $this->asFirst($claimSecond)
            ->postJson('/api/v1/ingest/transactions', $this->payload('order-first', 'customer-first', 'ONLY-SECOND'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.sku');

        $this->assertSame(0, Transaction::query()->where('organization_id', $this->first->id)->count());
        $this->assertSame(0, Customer::query()->where('organization_id', $this->first->id)->count());
        $this->assertSame($before, $this->secondSnapshot());
    }

    /**
     * Same transaction external id, customer external id and SKU as B: A gets its
     * own transaction, its own new customer and its own product.
     */
    #[DataProvider('organizationHeader')]
    public function test_coinciding_identifiers_resolve_only_inside_a(bool $claimSecond): void
    {
        $before = $this->secondSnapshot();

        $response = $this->asFirst($claimSecond)
            ->postJson('/api/v1/ingest/transactions', $this->payload('order-second', 'customer-second', 'SHARED'))
            ->assertCreated();

        $transaction = Transaction::query()->findOrFail($response->json('data.id'));
        $secondTransaction = Transaction::query()->where('organization_id', $this->second->id)->sole();

        $this->assertSame($this->first->id, $transaction->organization_id);
        $this->assertNotSame($secondTransaction->id, $transaction->id);
        $this->assertSame($this->first->id, $transaction->customer->organization_id);
        $this->assertNotSame($secondTransaction->customer_id, $transaction->customer_id);
        $this->assertSame(
            Product::query()->where('organization_id', $this->first->id)->where('sku', 'SHARED')->value('id'),
            $transaction->items()->sole()->product_id,
        );
        $this->assertSame($before, $this->secondSnapshot());
        $this->assertIngestionInvariants();
    }

    /**
     * B's dashboard, analytics and listing are the same before and after A
     * ingests a paid sale and moves another one through the lifecycle.
     */
    public function test_bs_aggregates_never_include_as_ingestions(): void
    {
        $uris = $this->secondAggregateUris();
        $before = array_map(fn (string $uri) => $this->asSecondOwner()->getJson($uri)->assertOk()->json(), $uris);

        $this->asFirst(true)
            ->postJson('/api/v1/ingest/transactions', $this->payload('order-paid', 'customer-first', 'SHARED'))
            ->assertCreated();
        $this->asFirst(true)
            ->postJson('/api/v1/ingest/transactions', [...$this->payload('order-pending', 'customer-first', 'SHARED'), 'status' => 'pending'])
            ->assertCreated();
        $this->asFirst(true)
            ->postJson('/api/v1/ingest/transactions/order-pending/status-changes', ['status' => 'paid', 'occurred_at' => now()->toIso8601String()])
            ->assertCreated();

        $this->assertSame(2, Transaction::query()->where('organization_id', $this->first->id)->count());

        foreach ($uris as $index => $uri) {
            $this->assertSame($before[$index], $this->asSecondOwner()->getJson($uri)->assertOk()->json(), $uri);
        }

        $this->assertIngestionInvariants();
    }

    /**
     * @return list<string>
     */
    private function secondAggregateUris(): array
    {
        $today = now($this->second->timezone);
        $period = "from={$today->copy()->subDay()->toDateString()}&to={$today->toDateString()}";

        return [
            "/api/v1/dashboard?{$period}",
            "/api/v1/analytics/revenue?{$period}",
            "/api/v1/analytics/products?{$period}",
            "/api/v1/analytics/customers?{$period}",
            "/api/v1/analytics/transactions?{$period}",
            '/api/v1/transactions',
            '/api/v1/customers',
            '/api/v1/products',
        ];
    }

    /**
     * Every row of organization B that ingestion or the lifecycle could touch.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function secondSnapshot(): array
    {
        $organizationId = $this->second->id;

        return [
            'transactions' => DB::table('transactions')->where('organization_id', $organizationId)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'transaction_items' => DB::table('transaction_items')
                ->whereIn('transaction_id', DB::table('transactions')->select('id')->where('organization_id', $organizationId))
                ->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'transaction_status_changes' => DB::table('transaction_status_changes')->where('organization_id', $organizationId)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'customers' => DB::table('customers')->where('organization_id', $organizationId)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'products' => DB::table('products')->where('organization_id', $organizationId)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        ];
    }

    private function asFirst(bool $claimSecond): static
    {
        $this->asIntegration($this->firstKey);

        return $claimSecond ? $this->withHeader('X-Organization-Id', $this->second->id) : $this->withoutHeader('X-Organization-Id');
    }

    private function asSecondOwner(): static
    {
        $this->withHeaders(['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000'])->withoutHeader('Authorization');

        return $this->actingInOrganization($this->secondOwner, $this->second);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $externalId, string $customerExternalId, string $sku): array
    {
        return [
            'external_id' => $externalId,
            'status' => 'paid',
            'occurred_at' => now()->subMinute()->toIso8601String(),
            'currency' => 'BRL',
            'customer' => [
                'external_id' => $customerExternalId,
                'name' => 'Isolated Customer',
                'email' => "{$customerExternalId}@example.com",
            ],
            'items' => [['sku' => $sku, 'quantity' => 1, 'unit_price' => '10.00']],
            'total_amount' => '10.00',
        ];
    }
}
