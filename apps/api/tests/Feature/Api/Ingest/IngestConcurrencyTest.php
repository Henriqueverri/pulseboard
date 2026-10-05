<?php

namespace Tests\Feature\Api\Ingest;

use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionStatusChange;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\Concerns\AssertsIngestionInvariants;
use Tests\Feature\Api\Concerns\InteractsWithIngestApi;
use Tests\Feature\Api\Concerns\SendsConcurrentRequests;
use Tests\TestCase;

/**
 * Ingestion and lifecycle under real concurrency: each request runs in its own
 * PHP process over its own PostgreSQL connection, released together by a barrier.
 *
 * PostgreSQL only, like the DST bucket tests. SQLite cannot exercise this: it
 * serializes every writer with a database-wide lock and has no row locks
 * (lockForUpdate compiles to nothing), so a "concurrent" SQLite test would only
 * prove sequential behavior. Data must be committed for other connections to
 * see it, so this uses DatabaseTruncation instead of RefreshDatabase and cleans
 * up after itself for the transactional tests that follow.
 */
class IngestConcurrencyTest extends TestCase
{
    use AssertsIngestionInvariants, InteractsWithIngestApi, SendsConcurrentRequests;
    use DatabaseTruncation {
        truncateDatabaseTables as truncateAllDatabaseTables;
    }

    private const WORKERS = 5;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Concurrency needs PostgreSQL: separate connections, row locks and concurrent commits.');
        }

        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());

        // Every concurrent status change carries the same occurred_at, so the
        // chronology rule never decides the outcome instead of the lock.
        $this->freezeSecond();
    }

    /**
     * SQLite's in-memory database is recreated per test and is skipped anyway.
     */
    protected function truncateDatabaseTables(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateAllDatabaseTables();
        }
    }

    public function test_identical_concurrent_ingestions_create_exactly_one_transaction(): void
    {
        $organization = Organization::factory()->create(['currency' => 'BRL']);
        [$apiKey, $plainTextKey] = $this->issueApiKey($organization);
        $products = $this->products($organization, ['SKU-A', 'SKU-B', 'SKU-C']);
        $payload = $this->ingestPayload('order-race', 'customer-race', ['SKU-A' => 1, 'SKU-B' => 2, 'SKU-C' => 3]);

        $results = $this->sendConcurrently(array_fill(0, self::WORKERS, [
            'method' => 'POST',
            'uri' => '/api/v1/ingest/transactions',
            'key' => $plainTextKey,
            'body' => $payload,
        ]));

        $this->assertRequestsOverlapped($results);
        $this->assertSame([200, 200, 200, 200, 201], $this->sortedStatuses($results));

        $transaction = Transaction::query()->sole();

        foreach ($this->responses($results) as $response) {
            $response->assertJsonPath('data.id', $transaction->id)
                ->assertJsonPath('data.status', 'paid')
                ->assertJsonPath('data.total_amount', '60.00')
                ->assertJsonCount(3, 'data.items')
                ->assertJsonCount(1, 'data.status_history');

            $response->getStatusCode() === 201
                ? $response->assertHeaderMissing('Idempotent-Replayed')
                : $response->assertHeader('Idempotent-Replayed', 'true');
        }

        $this->assertSame($organization->id, $transaction->organization_id);
        $this->assertSame($apiKey->id, $transaction->api_key_id);
        $this->assertSame(TransactionSource::Ingest, $transaction->source);
        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(3, DB::table('transaction_items')->count());
        $this->assertEqualsCanonicalizing(
            $products->pluck('id')->all(),
            $transaction->items()->pluck('product_id')->all(),
        );
        $this->assertSame(1, TransactionStatusChange::query()->count());
        $this->assertNull($transaction->statusChanges()->sole()->from_status);
        $this->assertIngestionInvariants();
    }

    /**
     * The customer is created inside each request's transaction: the others
     * block on the unique index, then reuse the committed row (createOrFirst).
     */
    public function test_concurrent_ingestions_of_one_new_customer_create_it_once(): void
    {
        $organization = Organization::factory()->create(['currency' => 'BRL']);
        [, $plainTextKey] = $this->issueApiKey($organization);
        $this->products($organization, ['SKU-A']);

        $results = $this->sendConcurrently(array_map(fn (int $n): array => [
            'method' => 'POST',
            'uri' => '/api/v1/ingest/transactions',
            'key' => $plainTextKey,
            'body' => $this->ingestPayload("order-{$n}", 'customer-shared', ['SKU-A' => 1]),
        ], range(1, self::WORKERS)));

        $this->assertRequestsOverlapped($results);
        $this->assertSame(array_fill(0, self::WORKERS, 201), $this->sortedStatuses($results));

        $customer = Customer::query()->sole();

        $this->assertSame('customer-shared', $customer->external_id);
        $this->assertSame(self::WORKERS, Transaction::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(self::WORKERS, TransactionStatusChange::query()->count());
        $this->assertIngestionInvariants();
    }

    /**
     * Two different payloads race for one external id: whichever commits first is
     * the transaction, its twin replays, the other payload is a conflict.
     */
    public function test_conflicting_concurrent_payloads_keep_only_the_winner(): void
    {
        $organization = Organization::factory()->create(['currency' => 'BRL']);
        [, $plainTextKey] = $this->issueApiKey($organization);
        $this->products($organization, ['SKU-A']);
        $payloads = [
            'one' => $this->ingestPayload('order-race', 'customer-race', ['SKU-A' => 1]),
            'two' => $this->ingestPayload('order-race', 'customer-race', ['SKU-A' => 2]),
        ];
        $sent = ['one', 'two', 'one', 'two'];

        $results = $this->sendConcurrently(array_map(fn (string $variant): array => [
            'method' => 'POST',
            'uri' => '/api/v1/ingest/transactions',
            'key' => $plainTextKey,
            'body' => $payloads[$variant],
        ], $sent));

        $this->assertRequestsOverlapped($results);

        $winner = null;

        foreach ($results as $index => $result) {
            if ($result['response']->getStatusCode() === 201) {
                $this->assertNull($winner, 'a single request creates the transaction');
                $winner = $sent[$index];
            }
        }

        $this->assertNotNull($winner);

        foreach ($results as $index => $result) {
            if ($result['response']->getStatusCode() === 201) {
                continue;
            }

            $sent[$index] === $winner
                ? $result['response']->assertOk()->assertHeader('Idempotent-Replayed', 'true')
                : $result['response']->assertConflict()->assertJsonPath('code', 'transaction_conflict');
        }

        $transaction = Transaction::query()->sole();

        $this->assertSame($payloads[$winner]['items'][0]['quantity'], $transaction->items()->sole()->quantity);
        $this->assertSame($payloads[$winner]['total_amount'], $transaction->total_amount);
        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(1, TransactionStatusChange::query()->count());
        $this->assertIngestionInvariants();
    }

    /**
     * Same transition five times at once: the row lock lets one apply it and the
     * rest find the transaction already there (200, no history row). Without the
     * lock they would all read pending and race on the history's unique index.
     */
    public function test_identical_concurrent_transitions_are_applied_once(): void
    {
        [$plainTextKey, $transaction] = $this->ingestedTransaction(TransactionStatus::Pending);

        $results = $this->sendConcurrently(array_fill(0, self::WORKERS, $this->statusChange($plainTextKey, 'order-1', TransactionStatus::Paid)));

        $this->assertRequestsOverlapped($results);
        $this->assertSame([200, 200, 200, 200, 201], $this->sortedStatuses($results));

        foreach ($this->responses($results) as $response) {
            $response->assertJsonPath('data.status', 'paid')->assertJsonPath('data.status_history.*.to_status', ['pending', 'paid']);
        }

        $transaction->refresh();

        $this->assertSame(TransactionStatus::Paid, $transaction->status);
        $this->assertSame(
            [[null, 'pending'], ['pending', 'paid']],
            $this->history($transaction),
        );
        $this->assertIngestionInvariants();
    }

    /**
     * pending -> paid and pending -> canceled at once. Without the lock both
     * could read pending and both write, leaving two changes out of pending.
     * With it, the first one wins and the other sees a final or settled state.
     */
    public function test_competing_concurrent_transitions_are_serialized_by_the_row_lock(): void
    {
        [$plainTextKey, $transaction] = $this->ingestedTransaction(TransactionStatus::Pending);
        $sent = [TransactionStatus::Paid, TransactionStatus::Canceled, TransactionStatus::Paid, TransactionStatus::Canceled];

        $results = $this->sendConcurrently(array_map(
            fn (TransactionStatus $status): array => $this->statusChange($plainTextKey, 'order-1', $status),
            $sent,
        ));

        $this->assertRequestsOverlapped($results);

        $transaction->refresh();
        $winner = $transaction->status;

        $this->assertContains($winner, [TransactionStatus::Paid, TransactionStatus::Canceled]);

        foreach ($results as $index => $result) {
            if ($sent[$index] !== $winner) {
                $result['response']->assertConflict()->assertJsonPath('code', 'invalid_transition');
            }
        }

        $winnerStatuses = $this->sortedStatuses(array_values(array_filter(
            $results,
            fn (array $result, int $index): bool => $sent[$index] === $winner,
            ARRAY_FILTER_USE_BOTH,
        )));

        $this->assertSame([200, 201], $winnerStatuses);
        $this->assertSame([[null, 'pending'], ['pending', $winner->value]], $this->history($transaction));
        $this->assertIngestionInvariants();
    }

    /**
     * paid and refunded at once on a pending transaction. Both orders are legal
     * and the lock picks one: refunded first is rejected (pending -> refunded),
     * paid first lets the refund through. Whatever happened, the database must
     * match the responses exactly.
     */
    public function test_dependent_concurrent_transitions_follow_the_order_of_the_lock(): void
    {
        [$plainTextKey, $transaction] = $this->ingestedTransaction(TransactionStatus::Pending);
        $sent = [TransactionStatus::Paid, TransactionStatus::Refunded, TransactionStatus::Paid, TransactionStatus::Refunded, TransactionStatus::Paid, TransactionStatus::Refunded];

        $results = $this->sendConcurrently(array_map(
            fn (TransactionStatus $status): array => $this->statusChange($plainTextKey, 'order-1', $status),
            $sent,
        ));

        $this->assertRequestsOverlapped($results);

        $applied = [];

        foreach ($results as $index => $result) {
            $response = $result['response'];
            $status = $response->getStatusCode();

            $this->assertContains($status, [200, 201, 409]);

            match ($status) {
                201 => $applied[] = $sent[$index],
                200 => $response->assertJsonPath('data.status', $sent[$index]->value),
                409 => $response->assertJsonPath('code', 'invalid_transition'),
            };
        }

        $transaction->refresh();
        $refunded = in_array(TransactionStatus::Refunded, $applied, true);

        $this->assertSame(1, count(array_filter($applied, fn (TransactionStatus $status): bool => $status === TransactionStatus::Paid)));
        $this->assertSame($refunded ? TransactionStatus::Refunded : TransactionStatus::Paid, $transaction->status);
        $this->assertSame(
            $refunded
                ? [[null, 'pending'], ['pending', 'paid'], ['paid', 'refunded']]
                : [[null, 'pending'], ['pending', 'paid']],
            $this->history($transaction),
        );
        $this->assertSame(1 + count($applied), $transaction->statusChanges()->count(), 'one change per applied transition');
        $this->assertIngestionInvariants();
    }

    /**
     * Two organizations ingest the same external id, customer id and SKU at the
     * same time; B's requests also claim A's id in X-Organization-Id. Each key
     * only ever touches its own organization's rows.
     */
    public function test_concurrent_ingestions_of_two_organizations_stay_isolated(): void
    {
        $first = Organization::factory()->create(['currency' => 'BRL']);
        $second = Organization::factory()->create(['currency' => 'BRL']);
        [$firstApiKey, $firstKey] = $this->issueApiKey($first);
        [$secondApiKey, $secondKey] = $this->issueApiKey($second);
        $firstProduct = $this->products($first, ['SHARED'])->sole();
        $secondProduct = $this->products($second, ['SHARED'])->sole();
        $payload = $this->ingestPayload('order-shared', 'customer-shared', ['SHARED' => 1]);
        $sent = [$first, $second, $first, $second, $first, $second];

        $results = $this->sendConcurrently(array_map(fn (Organization $organization): array => [
            'method' => 'POST',
            'uri' => '/api/v1/ingest/transactions',
            'key' => $organization->is($first) ? $firstKey : $secondKey,
            'headers' => ['X-Organization-Id' => $first->id],
            'body' => $payload,
        ], $sent));

        $this->assertRequestsOverlapped($results);

        $transactions = Transaction::query()->get()->keyBy('organization_id');

        $this->assertCount(2, $transactions);

        foreach ([[$first, $firstApiKey, $firstProduct], [$second, $secondApiKey, $secondProduct]] as [$organization, $apiKey, $product]) {
            $transaction = $transactions->get($organization->id);
            $ownResults = array_values(array_filter(
                $results,
                fn (array $result, int $index): bool => $sent[$index]->is($organization),
                ARRAY_FILTER_USE_BOTH,
            ));

            $this->assertSame([200, 200, 201], $this->sortedStatuses($ownResults), $organization->name);

            foreach ($this->responses($ownResults) as $response) {
                $response->assertJsonPath('data.id', $transaction->id)
                    ->assertJsonPath('data.customer.id', $transaction->customer_id)
                    ->assertJsonPath('data.items.0.product_id', $product->id);
            }

            $this->assertSame('order-shared', $transaction->external_id);
            $this->assertSame($apiKey->id, $transaction->api_key_id);
            $this->assertSame($organization->id, $transaction->customer->organization_id);
            $this->assertSame([$product->id], $transaction->items()->pluck('product_id')->all());
            $this->assertSame(1, Customer::query()->where('organization_id', $organization->id)->count());
        }

        $this->assertNotSame($transactions->get($first->id)->customer_id, $transactions->get($second->id)->customer_id);
        $this->assertIngestionInvariants();

        // Reading back with B's key and A's header still resolves B's own transaction.
        $this->asIntegration($secondKey)
            ->withHeader('X-Organization-Id', $first->id)
            ->getJson('/api/v1/ingest/transactions/order-shared')
            ->assertOk()
            ->assertJsonPath('data.id', $transactions->get($second->id)->id);
    }

    /**
     * Both organizations have a pending "order-1". Concurrent transitions sent
     * with each key (B's claiming A's id in the header) only lock and change the
     * key's own row.
     */
    public function test_concurrent_status_changes_of_two_organizations_stay_isolated(): void
    {
        [$firstKey, $firstTransaction, $first] = $this->ingestedTransaction(TransactionStatus::Pending);
        [$secondKey, $secondTransaction] = $this->ingestedTransaction(TransactionStatus::Pending);

        $results = $this->sendConcurrently([
            $this->statusChange($firstKey, 'order-1', TransactionStatus::Paid),
            $this->statusChange($secondKey, 'order-1', TransactionStatus::Canceled, ['X-Organization-Id' => $first->id]),
            $this->statusChange($secondKey, 'order-1', TransactionStatus::Canceled, ['X-Organization-Id' => $first->id]),
        ]);

        $this->assertRequestsOverlapped($results);

        $results[0]['response']->assertCreated()->assertJsonPath('data.id', $firstTransaction->id);
        $this->assertSame([200, 201], $this->sortedStatuses(array_slice($results, 1)));

        foreach ($this->responses(array_slice($results, 1)) as $response) {
            $response->assertJsonPath('data.id', $secondTransaction->id)->assertJsonPath('data.status', 'canceled');
        }

        $this->assertSame(TransactionStatus::Paid, $firstTransaction->refresh()->status);
        $this->assertSame(TransactionStatus::Canceled, $secondTransaction->refresh()->status);
        $this->assertSame([[null, 'pending'], ['pending', 'paid']], $this->history($firstTransaction));
        $this->assertSame([[null, 'pending'], ['pending', 'canceled']], $this->history($secondTransaction));
        $this->assertIngestionInvariants();
    }

    /**
     * A fresh organization with a transaction "order-1" created through the
     * ingestion endpoint itself, so it has a real null -> status history.
     *
     * @return array{0: string, 1: Transaction, 2: Organization}
     */
    private function ingestedTransaction(TransactionStatus $status): array
    {
        $organization = Organization::factory()->create(['currency' => 'BRL']);
        [, $plainTextKey] = $this->issueApiKey($organization);
        $this->products($organization, ['SKU-A']);

        $response = $this->asIntegration($plainTextKey)
            ->postJson('/api/v1/ingest/transactions', [
                ...$this->ingestPayload('order-1', 'customer-1', ['SKU-A' => 1]),
                'status' => $status->value,
                'occurred_at' => now()->subHour()->toIso8601String(),
            ])
            ->assertCreated();

        return [$plainTextKey, Transaction::query()->findOrFail($response->json('data.id')), $organization];
    }

    /**
     * @param  list<string>  $skus
     * @return Collection<int, Product>
     */
    private function products(Organization $organization, array $skus): Collection
    {
        return collect($skus)->map(fn (string $sku): Product => Product::factory()->for($organization)->create(['sku' => $sku]));
    }

    /**
     * @param  array<string, int>  $quantities  SKU => quantity, at 10.00 each
     * @return array<string, mixed>
     */
    private function ingestPayload(string $externalId, string $customerExternalId, array $quantities): array
    {
        $items = [];

        foreach ($quantities as $sku => $quantity) {
            $items[] = ['sku' => $sku, 'quantity' => $quantity, 'unit_price' => '10.00'];
        }

        return [
            'external_id' => $externalId,
            'status' => 'paid',
            'occurred_at' => now()->subMinute()->toIso8601String(),
            'currency' => 'BRL',
            'customer' => [
                'external_id' => $customerExternalId,
                'name' => 'Concurrent Customer',
                'email' => "{$customerExternalId}@example.com",
            ],
            'items' => $items,
            'total_amount' => number_format(array_sum($quantities) * 10, 2, '.', ''),
        ];
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{method: string, uri: string, key: string, body: array<string, string>, headers: array<string, string>}
     */
    private function statusChange(string $plainTextKey, string $externalId, TransactionStatus $status, array $headers = []): array
    {
        return [
            'method' => 'POST',
            'uri' => "/api/v1/ingest/transactions/{$externalId}/status-changes",
            'key' => $plainTextKey,
            'body' => ['status' => $status->value, 'occurred_at' => now()->toIso8601String()],
            'headers' => $headers,
        ];
    }

    /**
     * The persisted chain from creation, as [from, to] pairs.
     *
     * @return list<array{0: string|null, 1: string}>
     */
    private function history(Transaction $transaction): array
    {
        $byFrom = $transaction->statusChanges()->get()->keyBy(fn (TransactionStatusChange $change) => $change->from_status->value ?? '');
        $chain = [];
        $from = '';

        while (count($chain) < $byFrom->count() && ($change = $byFrom->get($from)) !== null) {
            $chain[] = [$change->from_status?->value, $change->to_status->value];
            $from = $change->to_status->value;
        }

        $this->assertCount($byFrom->count(), $chain, 'the history is a single chain');

        return $chain;
    }

    /**
     * @param  list<array{response: TestResponse, started_at: float, finished_at: float}>  $results
     * @return list<TestResponse>
     */
    private function responses(array $results): array
    {
        return array_column($results, 'response');
    }
}
