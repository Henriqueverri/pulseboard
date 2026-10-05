<?php

namespace Tests\Feature\Api\Ingest;

use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionStatusChange;
use App\Providers\AppServiceProvider;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Concerns\InteractsWithIngestApi;
use Tests\TestCase;

/**
 * Query budget of the integration API, measured on the real code (identical on
 * SQLite and PostgreSQL) and broken down per statement as "verb table": a
 * regression names the query it added, and a query per item or per product
 * changes the breakdown instead of hiding inside a total.
 *
 * Laravel reports only statements that succeed, so the INSERT that hits the
 * unique index on a replay or conflict is not in these counts.
 *
 * The test cache store is array, so the rate limiter costs nothing here; on the
 * production database store it adds a constant amount, covered separately.
 */
class IngestQueryBudgetTest extends TestCase
{
    use InteractsWithIngestApi, RefreshDatabase;

    /**
     * Key by its unique prefix, with its organization eager loaded.
     */
    private const AUTHENTICATION = ['select api_keys' => 1, 'select organizations' => 1];

    /**
     * IngestedTransactionResource relations, one query each whatever the number of items.
     */
    private const RESPONSE_RELATIONS = [
        'select customers' => 1,
        'select transaction_items' => 1,
        'select products' => 1,
        'select transaction_status_changes' => 1,
    ];

    /**
     * Before the insert: every SKU in one whereIn, then the customer by external id.
     */
    private const RESOLUTION = ['select products' => 1, 'select customers' => 1];

    /**
     * One row each: the transaction, every item in a single multi-row insert,
     * and the null -> status history row.
     */
    private const CREATION_WRITES = [
        'insert transactions' => 1,
        'insert transaction_items' => 1,
        'insert transaction_status_changes' => 1,
    ];

    /**
     * The rate limiter on the database cache store: measured at 8 statements on
     * the first hit of a window and 6 afterwards (Laravel's RateLimiter and
     * DatabaseStore), all on the cache table.
     */
    private const DATABASE_THROTTLE_MAX = 8;

    private Organization $organization;

    private ApiKey $apiKey;

    private string $plainTextKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['currency' => 'BRL']);
        // Used within the last minute, so last_used_at is not written (measured on its own below).
        [$this->apiKey, $this->plainTextKey] = $this->issueApiKey($this->organization, ['last_used_at' => now()]);
    }

    public function test_get_reads_the_key_the_transaction_and_its_relations(): void
    {
        $this->ingest($this->payload('order-1', $this->products(3)))->assertCreated();

        $this->assertSame(
            $this->budget(self::AUTHENTICATION, ['select transactions' => 1], self::RESPONSE_RELATIONS),
            $this->measure(fn () => $this->fetch('/api/v1/ingest/transactions/order-1')->assertOk()),
        );
    }

    public function test_get_of_an_unknown_external_id_stops_after_the_lookup(): void
    {
        $this->assertSame(
            $this->budget(self::AUTHENTICATION, ['select transactions' => 1]),
            $this->measure(fn () => $this->fetch('/api/v1/ingest/transactions/missing')->assertNotFound()),
        );
    }

    /**
     * After the commit the transaction is read back and its relations loaded for
     * the response, exactly as the GET does.
     */
    public function test_creation_with_a_new_customer(): void
    {
        $payload = $this->payload('order-1', $this->products(3));

        $this->assertSame(
            $this->budget(
                self::AUTHENTICATION,
                self::RESOLUTION,
                ['insert customers' => 1],
                self::CREATION_WRITES,
                ['select transactions' => 1],
                self::RESPONSE_RELATIONS,
            ),
            $this->measure(fn () => $this->ingest($payload)->assertCreated()),
        );
    }

    public function test_creation_with_an_existing_customer_does_not_write_it(): void
    {
        Customer::factory()->for($this->organization)->create(['external_id' => 'customer-1', 'email' => 'customer-1@example.com']);
        $payload = $this->payload('order-1', $this->products(3));

        $this->assertSame(
            $this->budget(
                self::AUTHENTICATION,
                self::RESOLUTION,
                self::CREATION_WRITES,
                ['select transactions' => 1],
                self::RESPONSE_RELATIONS,
            ),
            $this->measure(fn () => $this->ingest($payload)->assertCreated()),
        );
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function itemCounts(): array
    {
        return [
            '2 items' => [2],
            '10 items' => [10],
            '100 items (the maximum)' => [100],
        ];
    }

    /**
     * N+1 guard: products are resolved in one query and items inserted in one
     * statement, so 100 items cost exactly what 1 item costs.
     */
    #[DataProvider('itemCounts')]
    public function test_creation_does_not_grow_with_the_number_of_items(int $items): void
    {
        $singlePayload = $this->payload('order-single', $this->products(1, 'ONE'));
        $manyPayload = $this->payload('order-many', $this->products($items, 'MANY'), 'customer-2');

        $single = $this->measure(fn () => $this->ingest($singlePayload)->assertCreated());
        $many = $this->measure(fn () => $this->ingest($manyPayload)->assertCreated());

        $this->assertSame($single, $many);
        $this->assertSame(2, $many['select products'], 'one lookup to resolve every SKU, one to load the response');
        $this->assertSame(1, $many['insert transaction_items'], 'a single multi-row insert');
        $this->assertSame($items, Transaction::query()->where('external_id', 'order-many')->sole()->items()->count());
    }

    #[DataProvider('itemCounts')]
    public function test_reads_replays_and_status_changes_do_not_grow_with_the_number_of_items(int $items): void
    {
        $payloads = [
            'order-single' => [...$this->payload('order-single', $this->products(1, 'ONE')), 'status' => 'pending'],
            'order-many' => [...$this->payload('order-many', $this->products($items, 'MANY'), 'customer-2'), 'status' => 'pending'],
        ];

        foreach ($payloads as $payload) {
            $this->ingest($payload)->assertCreated();
        }

        $operations = [
            'get' => fn (string $id) => $this->fetch("/api/v1/ingest/transactions/{$id}")->assertOk(),
            'replay' => fn (string $id) => $this->ingest($payloads[$id])->assertOk(),
            'status change' => fn (string $id) => $this->changeStatus($id, 'paid')->assertCreated(),
        ];

        foreach ($operations as $name => $operation) {
            $this->assertSame(
                $this->measure(fn () => $operation('order-single')),
                $this->measure(fn () => $operation('order-many')),
                $name,
            );
        }
    }

    /**
     * A replay finds the existing transaction through the failed insert and only
     * reads: nothing is created or updated again.
     */
    public function test_an_idempotent_replay_only_reads(): void
    {
        $payload = $this->payload('order-1', $this->products(3));
        $first = $this->measure(fn () => $this->ingest($payload)->assertCreated());
        $counts = $this->rowCounts();

        $replay = $this->measure(fn () => $this->ingest($payload)->assertOk()->assertHeader('Idempotent-Replayed', 'true'));

        $this->assertSame(
            $this->budget(self::AUTHENTICATION, self::RESOLUTION, ['select transactions' => 1], self::RESPONSE_RELATIONS),
            $replay,
        );
        $this->assertSame([], $this->writes($replay));
        $this->assertNotSame([], $this->writes($first));
        $this->assertLessThan(array_sum($first), array_sum($replay));
        $this->assertSame($counts, $this->rowCounts());
    }

    public function test_a_conflicting_payload_is_rejected_after_reading_the_existing_transaction(): void
    {
        $products = $this->products(1);
        $this->ingest($this->payload('order-1', $products))->assertCreated();
        $payload = $this->payload('order-1', $products);
        $payload['items'][0]['quantity'] = 2;
        $payload['total_amount'] = '20.00';

        $conflict = $this->measure(fn () => $this->ingest($payload)->assertConflict());

        $this->assertSame($this->budget(self::AUTHENTICATION, self::RESOLUTION, ['select transactions' => 1]), $conflict);
        $this->assertSame([], $this->writes($conflict));
    }

    /**
     * Lock the row, read the last occurred_at for the chronology rule, update the
     * status, record the change. The locked row is reused for the response.
     */
    public function test_an_applied_status_change(): void
    {
        $this->ingest([...$this->payload('order-1', $this->products(3)), 'status' => 'pending'])->assertCreated();

        $this->assertSame(
            $this->budget(
                self::AUTHENTICATION,
                ['select transactions' => 1, 'select transaction_status_changes' => 1, 'update transactions' => 1, 'insert transaction_status_changes' => 1],
                self::RESPONSE_RELATIONS,
            ),
            $this->measure(fn () => $this->changeStatus('order-1', 'paid')->assertCreated()),
        );
    }

    public function test_a_status_change_to_the_current_status_only_reads(): void
    {
        $this->ingest($this->payload('order-1', $this->products(1)))->assertCreated();

        $this->assertSame(
            $this->budget(self::AUTHENTICATION, ['select transactions' => 1], self::RESPONSE_RELATIONS),
            $this->measure(fn () => $this->changeStatus('order-1', 'paid')->assertOk()),
        );
    }

    public function test_a_rejected_status_change_stops_after_the_locked_read(): void
    {
        $this->ingest($this->payload('order-1', $this->products(1)))->assertCreated();

        $this->assertSame(
            $this->budget(self::AUTHENTICATION, ['select transactions' => 1]),
            $this->measure(fn () => $this->changeStatus('order-1', 'canceled')->assertConflict()),
        );
    }

    /**
     * SQLite has no row locks (lockForUpdate compiles to nothing there); the
     * concurrency tests prove the PostgreSQL lock actually serializes requests.
     */
    public function test_the_status_change_reads_the_transaction_with_a_row_lock(): void
    {
        $this->ingest([...$this->payload('order-1', $this->products(1)), 'status' => 'pending'])->assertCreated();

        $queries = $this->queries(fn () => $this->changeStatus('order-1', 'paid')->assertCreated());
        $lookup = array_values(array_filter($queries, fn (string $sql): bool => $this->label($sql) === 'select transactions'));

        $this->assertCount(1, $lookup);

        DB::getDriverName() === 'pgsql'
            ? $this->assertStringEndsWith('for update', $lookup[0])
            : $this->assertStringNotContainsString('for update', $lookup[0]);
    }

    /**
     * last_used_at is written at most once a minute per key: one extra statement
     * on the first request of the window, none afterwards.
     */
    public function test_the_key_usage_write_is_one_statement_and_only_when_stale(): void
    {
        $this->apiKey->forceFill(['last_used_at' => now()->subMinutes(2)])->save();

        $stale = $this->measure(fn () => $this->fetch('/api/v1/ingest/transactions/missing')->assertNotFound());
        $fresh = $this->measure(fn () => $this->fetch('/api/v1/ingest/transactions/missing')->assertNotFound());

        $this->assertSame($this->budget($fresh, ['update api_keys' => 1]), $stale);
        $this->assertSame($this->budget(self::AUTHENTICATION, ['select transactions' => 1]), $fresh);
    }

    /**
     * With the production cache store the throttle adds a bounded number of
     * statements on the cache table only, the same for every endpoint and
     * whatever the number of items; the application's own queries do not change.
     */
    public function test_the_rate_limiter_on_the_database_store_adds_a_constant_cost(): void
    {
        $this->useDatabaseRateLimiter();
        $payload = [...$this->payload('order-1', $this->products(100)), 'status' => 'pending'];
        $this->ingest($payload)->assertCreated();

        $operations = [
            'get' => fn () => $this->fetch('/api/v1/ingest/transactions/order-1')->assertOk(),
            'replay' => fn () => $this->ingest($payload)->assertOk(),
            'status change' => fn () => $this->changeStatus('order-1', 'paid')->assertCreated(),
        ];

        foreach ($operations as $name => $operation) {
            $counts = $this->measure($operation);
            $throttle = array_filter($counts, fn (string $label): bool => str_ends_with($label, ' cache'), ARRAY_FILTER_USE_KEY);

            $this->assertNotEmpty($throttle, "{$name} is throttled through the cache table");
            $this->assertLessThanOrEqual(self::DATABASE_THROTTLE_MAX, array_sum($throttle), $name);
        }
    }

    /**
     * Sums budgets given as "verb table" => count maps.
     *
     * @param  array<string, int>  ...$parts
     * @return array<string, int>
     */
    private function budget(array ...$parts): array
    {
        $total = [];

        foreach ($parts as $part) {
            foreach ($part as $label => $count) {
                $total[$label] = ($total[$label] ?? 0) + $count;
            }
        }

        ksort($total);

        return $total;
    }

    /**
     * @return array<string, int>
     */
    private function measure(callable $request): array
    {
        $counts = array_count_values(array_map($this->label(...), $this->queries($request)));
        ksort($counts);

        return $counts;
    }

    /**
     * SQL of every statement run during the request, and nothing from the setup.
     *
     * @return list<string>
     */
    private function queries(callable $request): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $request();

            return array_values(array_column(DB::getQueryLog(), 'query'));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    /**
     * "select products", "insert transaction_items", "update api_keys"...
     */
    private function label(string $sql): string
    {
        if (preg_match('/^\s*(select|insert|update|delete)\b(?:.*?\b(?:from|into))?\s+"([a-z_]+)"/is', $sql, $match) !== 1) {
            $this->fail("Unrecognized statement: {$sql}");
        }

        return strtolower($match[1]).' '.$match[2];
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private function writes(array $counts): array
    {
        return array_filter($counts, fn (string $label): bool => ! str_starts_with($label, 'select '), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        return [
            'customers' => Customer::query()->count(),
            'transactions' => Transaction::query()->count(),
            'transaction_items' => DB::table('transaction_items')->count(),
            'transaction_status_changes' => TransactionStatusChange::query()->count(),
        ];
    }

    /**
     * Rebuilds the rate limiter on the database store and registers the real
     * "ingest" limiter on it again.
     */
    private function useDatabaseRateLimiter(): void
    {
        config(['cache.default' => 'database', 'cache.limiter' => 'database']);
        $this->app->forgetInstance(RateLimiter::class);
        RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
        $this->app->getProvider(AppServiceProvider::class)->boot();
    }

    /**
     * @return list<Product>
     */
    private function products(int $count, string $prefix = 'SKU'): array
    {
        return array_map(
            fn (int $n): Product => Product::factory()->for($this->organization)->create(['sku' => sprintf('%s-%03d', $prefix, $n)]),
            range(1, $count),
        );
    }

    /**
     * @param  list<Product>  $products
     * @return array<string, mixed>
     */
    private function payload(string $externalId, array $products, string $customerExternalId = 'customer-1'): array
    {
        return [
            'external_id' => $externalId,
            'status' => 'paid',
            'occurred_at' => now()->subMinute()->toIso8601String(),
            'currency' => 'BRL',
            'customer' => [
                'external_id' => $customerExternalId,
                'name' => 'Budget Customer',
                'email' => "{$customerExternalId}@example.com",
            ],
            'items' => array_map(fn (Product $product): array => [
                'sku' => $product->sku,
                'quantity' => 1,
                'unit_price' => '10.00',
            ], $products),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function ingest(array $payload): TestResponse
    {
        return $this->asIntegration($this->plainTextKey)->postJson('/api/v1/ingest/transactions', $payload);
    }

    private function fetch(string $uri): TestResponse
    {
        return $this->asIntegration($this->plainTextKey)->getJson($uri);
    }

    private function changeStatus(string $externalId, string $status): TestResponse
    {
        return $this->asIntegration($this->plainTextKey)->postJson(
            "/api/v1/ingest/transactions/{$externalId}/status-changes",
            ['status' => $status, 'occurred_at' => now()->toIso8601String()],
        );
    }
}
