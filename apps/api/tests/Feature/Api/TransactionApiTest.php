<?php

namespace Tests\Feature\Api;

use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

class TransactionApiTest extends TestCase
{
    use InteractsWithOrganizationApi, RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->owner = $this->memberOf($this->organization);
        $this->customer = Customer::factory()->for($this->organization)->create([
            'name' => 'Maria Souza',
            'email' => 'maria@example.com',
        ]);
        $this->product = Product::factory()->for($this->organization)->create(['price' => '50.00']);
    }

    // Index

    public function test_guest_receives_401(): void
    {
        $this->withHeader('X-Organization-Id', $this->organization->id)
            ->getJson('/api/v1/transactions')
            ->assertUnauthorized();
    }

    public function test_index_returns_transactions_of_every_status_with_customer_and_items_count(): void
    {
        foreach (TransactionStatus::cases() as $status) {
            $this->createTransaction($this->customer, [[$this->product, 1, '50.00'], [$this->product, 2, '10.00']], $status);
        }

        $response = $this->listTransactions()
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'status', 'total_amount', 'occurred_at', 'items_count', 'customer' => ['id', 'name', 'email', 'is_deleted']]],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'per_page', 'total', 'last_page'],
            ])
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('data.0.items_count', 2)
            ->assertJsonPath('data.0.total_amount', '70.00')
            ->assertJsonPath('data.0.customer.id', $this->customer->id)
            ->assertJsonMissingPath('data.0.items')
            ->assertJsonMissingPath('data.0.organization_id');

        $this->assertEqualsCanonicalizing(
            ['paid', 'refunded', 'pending', 'canceled'],
            $response->json('data.*.status'),
        );
    }

    public function test_index_honours_page_and_per_page(): void
    {
        foreach (range(1, 5) as $day) {
            $this->createTransaction($this->customer, [[$this->product, 1, '10.00']], occurredAt: "2026-09-0{$day} 10:00:00");
        }

        $response = $this->listTransactions('per_page=2&page=3')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.total', 5);

        $this->assertStringContainsString('per_page=2', $response->json('links.first'));
    }

    public function test_index_is_sorted_by_occurred_at_desc_then_id_desc(): void
    {
        $oldest = $this->createTransaction($this->customer, [[$this->product, 1, '10.00']], occurredAt: '2026-09-01 10:00:00');
        $newest = $this->createTransaction($this->customer, [[$this->product, 1, '10.00']], occurredAt: '2026-09-03 10:00:00');
        $tieA = $this->createTransaction($this->customer, [[$this->product, 1, '10.00']], occurredAt: '2026-09-02 10:00:00');
        $tieB = $this->createTransaction($this->customer, [[$this->product, 1, '10.00']], occurredAt: '2026-09-02 10:00:00');

        $tied = collect([$tieA->id, $tieB->id])->sortDesc()->values()->all();

        $this->listTransactions()
            ->assertOk()
            ->assertJsonPath('data.*.id', [$newest->id, ...$tied, $oldest->id]);
    }

    public function test_index_filters_by_status(): void
    {
        $paid = $this->createTransaction($this->customer, [[$this->product, 1, '10.00']]);
        $this->createTransaction($this->customer, [[$this->product, 1, '10.00']], TransactionStatus::Refunded);
        $this->createTransaction($this->customer, [[$this->product, 1, '10.00']], TransactionStatus::Canceled);

        $this->listTransactions('status=paid')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$paid->id]);
    }

    public function test_index_searches_by_transaction_uuid(): void
    {
        $target = $this->createTransaction($this->customer, [[$this->product, 1, '10.00']]);
        $this->createTransaction($this->customer, [[$this->product, 1, '10.00']]);

        $this->listTransactions('q='.strtoupper($target->id))
            ->assertOk()
            ->assertJsonPath('data.*.id', [$target->id]);
    }

    public function test_index_exposes_external_id_and_source(): void
    {
        $seeded = $this->createTransaction($this->customer, [[$this->product, 1, '10.00']], occurredAt: '2026-09-01 12:00:00');
        $ingested = $this->createTransaction($this->customer, [[$this->product, 1, '10.00']], occurredAt: '2026-09-02 12:00:00');
        $ingested->forceFill(['external_id' => 'order_123', 'source' => TransactionSource::Ingest])->save();

        $this->listTransactions()
            ->assertOk()
            ->assertJsonPath('data.0.id', $ingested->id)
            ->assertJsonPath('data.0.external_id', 'order_123')
            ->assertJsonPath('data.0.source', 'ingest')
            ->assertJsonPath('data.1.id', $seeded->id)
            ->assertJsonPath('data.1.external_id', null)
            ->assertJsonPath('data.1.source', 'seed')
            ->assertJsonMissingPath('data.0.status_history');
    }

    public function test_index_searches_by_exact_external_id(): void
    {
        $target = $this->createTransaction($this->customer, [[$this->product, 1, '10.00']]);
        $target->forceFill(['external_id' => 'order_123'])->save();
        $other = $this->createTransaction($this->customer, [[$this->product, 1, '10.00']]);
        $other->forceFill(['external_id' => 'order_1234'])->save();

        $this->listTransactions('q=order_123')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$target->id]);

        $this->listTransactions('q=order_12')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_index_searches_uuid_shaped_external_id_and_transaction_id(): void
    {
        $externalId = '7a1f6c2e-5b1d-4c3a-9e2f-1d2c3b4a5e6f';
        $byExternalId = $this->createTransaction($this->customer, [[$this->product, 1, '10.00']], occurredAt: '2026-09-01 12:00:00');
        $byExternalId->forceFill(['external_id' => $externalId])->save();
        $byId = $this->createTransaction($this->customer, [[$this->product, 1, '10.00']], occurredAt: '2026-09-02 12:00:00');

        $this->listTransactions("q={$externalId}")
            ->assertOk()
            ->assertJsonPath('data.*.id', [$byExternalId->id]);

        $this->listTransactions("q={$byId->id}")
            ->assertOk()
            ->assertJsonPath('data.*.id', [$byId->id]);
    }

    public function test_index_search_by_external_id_stays_within_the_organization(): void
    {
        [$foreign] = $this->foreignTenantTransaction();
        $foreign->forceFill(['external_id' => 'order_123'])->save();

        $this->listTransactions('q=order_123')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_index_searches_by_customer_name_and_email_case_insensitively(): void
    {
        $other = Customer::factory()->for($this->organization)->create(['name' => 'João Lima', 'email' => 'jlima@corp.example']);
        $maria = $this->createTransaction($this->customer, [[$this->product, 1, '10.00']]);
        $joao = $this->createTransaction($other, [[$this->product, 1, '10.00']]);

        $this->listTransactions('q=SOUZA')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$maria->id]);

        $this->listTransactions('q=CORP.example')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$joao->id]);
    }

    public function test_index_filters_by_customer_id(): void
    {
        $other = Customer::factory()->for($this->organization)->create();
        $mine = $this->createTransaction($this->customer, [[$this->product, 1, '10.00']]);
        $this->createTransaction($other, [[$this->product, 1, '10.00']]);

        $this->listTransactions("customer_id={$this->customer->id}")
            ->assertOk()
            ->assertJsonPath('data.*.id', [$mine->id]);
    }

    public function test_index_filters_by_from_inclusive_start_of_day_in_organization_timezone(): void
    {
        // America/Sao_Paulo is UTC-3: Sep 10th starts at 03:00 UTC.
        $this->createTransaction($this->customer, [[$this->product, 1, '10.00']], occurredAt: '2026-09-10 02:59:59');
        $startOfDay = $this->createTransaction($this->customer, [[$this->product, 1, '10.00']], occurredAt: '2026-09-10 03:00:00');
        $later = $this->createTransaction($this->customer, [[$this->product, 1, '10.00']], occurredAt: '2026-09-15 12:00:00');

        $this->listTransactions('from=2026-09-10')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$later->id, $startOfDay->id]);
    }

    public function test_index_filters_by_to_inclusive_end_of_day_in_organization_timezone(): void
    {
        $earlier = $this->createTransaction($this->customer, [[$this->product, 1, '10.00']], occurredAt: '2026-09-05 08:00:00');
        $endOfDay = $this->createTransaction($this->customer, [[$this->product, 1, '10.00']], occurredAt: '2026-09-11 02:59:59');
        $this->createTransaction($this->customer, [[$this->product, 1, '10.00']], occurredAt: '2026-09-11 03:00:00');

        $this->listTransactions('to=2026-09-10')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$endOfDay->id, $earlier->id]);
    }

    public function test_evening_sale_in_sao_paulo_belongs_to_its_local_day(): void
    {
        // 23:30 on Sep 10th in São Paulo is 02:30 UTC on Sep 11th.
        $evening = $this->createTransaction($this->customer, [[$this->product, 1, '10.00']], occurredAt: '2026-09-11 02:30:00');

        $this->listTransactions('from=2026-09-10&to=2026-09-10')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$evening->id]);

        $this->listTransactions('from=2026-09-11&to=2026-09-11')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_date_filters_follow_each_organization_timezone(): void
    {
        $this->organization->update(['timezone' => 'Europe/Lisbon']);

        // Lisbon is UTC+1 in September: Sep 10th spans 2026-09-09 23:00 → 2026-09-10 23:00 UTC.
        $this->createTransaction($this->customer, [[$this->product, 1, '10.00']], occurredAt: '2026-09-09 22:59:59');
        $first = $this->createTransaction($this->customer, [[$this->product, 1, '10.00']], occurredAt: '2026-09-09 23:00:00');
        $last = $this->createTransaction($this->customer, [[$this->product, 1, '10.00']], occurredAt: '2026-09-10 22:59:59');
        $this->createTransaction($this->customer, [[$this->product, 1, '10.00']], occurredAt: '2026-09-10 23:00:00');

        $this->listTransactions('from=2026-09-10&to=2026-09-10')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$last->id, $first->id]);

        $this->organization->update(['timezone' => 'UTC']);

        $this->listTransactions('from=2026-09-10&to=2026-09-10')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissing(['id' => $first->id]);
    }

    public function test_timezone_cannot_be_chosen_by_the_client(): void
    {
        $evening = $this->createTransaction($this->customer, [[$this->product, 1, '10.00']], occurredAt: '2026-09-11 02:30:00');

        $this->listTransactions('from=2026-09-10&to=2026-09-10&timezone=UTC')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$evening->id]);
    }

    public function test_index_combines_from_and_to(): void
    {
        $this->createTransaction($this->customer, [[$this->product, 1, '10.00']], occurredAt: '2026-09-01 12:00:00');
        $inside = $this->createTransaction($this->customer, [[$this->product, 1, '10.00']], occurredAt: '2026-09-05 12:00:00');
        $this->createTransaction($this->customer, [[$this->product, 1, '10.00']], occurredAt: '2026-09-09 12:00:00');

        $this->listTransactions('from=2026-09-04&to=2026-09-06')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$inside->id]);

        $this->listTransactions('from=2026-09-05&to=2026-09-05')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$inside->id]);
    }

    public function test_index_rejects_from_after_to(): void
    {
        $this->listTransactions('from=2026-09-10&to=2026-09-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to']);
    }

    public function test_index_rejects_invalid_filters(): void
    {
        $this->listTransactions('status=shipped&customer_id=abc&from=10/09/2026&to=2026-02-30&per_page=101&page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status', 'customer_id', 'from', 'to', 'per_page', 'page']);
    }

    public function test_index_query_count_does_not_grow_with_results(): void
    {
        $this->createTransactions(2);
        $few = $this->countQueries(fn () => $this->listTransactions()->assertOk()->assertJsonCount(2, 'data'));

        $this->createTransactions(8);
        $many = $this->countQueries(fn () => $this->listTransactions()->assertOk()->assertJsonCount(10, 'data'));

        $this->assertSame($few, $many);
    }

    // Show

    public function test_show_returns_transaction_with_customer_items_and_products(): void
    {
        $second = Product::factory()->for($this->organization)->create(['name' => 'Cabo USB-C', 'sku' => 'CB-1']);
        $transaction = $this->createTransaction($this->customer, [[$this->product, 2, '49.90'], [$second, 3, '19.90']]);

        $response = $this->actingInOrganization($this->owner, $this->organization)
            ->getJson("/api/v1/transactions/{$transaction->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'id', 'status', 'total_amount', 'occurred_at',
                'customer' => ['id', 'name', 'email', 'is_deleted'],
                'items' => [['id', 'quantity', 'unit_price', 'line_total', 'product' => ['id', 'name', 'sku', 'is_deleted']]],
            ]])
            ->assertJsonMissingPath('data.organization_id')
            ->assertJsonMissingPath('data.items_count')
            ->assertJsonPath('data.id', $transaction->id)
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.customer.id', $this->customer->id)
            ->assertJsonPath('data.customer.email', 'maria@example.com')
            ->assertJsonPath('data.customer.is_deleted', false)
            ->assertJsonCount(2, 'data.items');

        $items = collect($response->json('data.items'))->keyBy('product.id');

        $this->assertSame(2, $items[$this->product->id]['quantity']);
        $this->assertSame('49.90', $items[$this->product->id]['unit_price']);
        $this->assertSame('99.80', $items[$this->product->id]['line_total']);
        $this->assertSame('Cabo USB-C', $items[$second->id]['product']['name']);
        $this->assertSame('CB-1', $items[$second->id]['product']['sku']);
        $this->assertSame('59.70', $items[$second->id]['line_total']);
        $this->assertSame('159.50', $response->json('data.total_amount'));
    }

    public function test_show_includes_external_id_source_and_status_history_in_lifecycle_order(): void
    {
        $transaction = Transaction::factory()
            ->for($this->organization)
            ->for($this->customer)
            ->status(TransactionStatus::Refunded)
            ->create(['occurred_at' => '2026-09-15 14:32:00']);

        $this->actingInOrganization($this->owner, $this->organization)
            ->getJson("/api/v1/transactions/{$transaction->id}")
            ->assertOk()
            ->assertJsonPath('data.external_id', null)
            ->assertJsonPath('data.source', 'seed')
            ->assertJsonCount(2, 'data.status_history')
            ->assertJsonPath('data.status_history.0.from_status', null)
            ->assertJsonPath('data.status_history.0.to_status', 'paid')
            ->assertJsonPath('data.status_history.1.from_status', 'paid')
            ->assertJsonPath('data.status_history.1.to_status', 'refunded')
            ->assertJsonPath('data.status_history.1.source', 'seed')
            ->assertJsonStructure(['data' => ['status_history' => [['from_status', 'to_status', 'occurred_at', 'recorded_at', 'source']]]]);
    }

    public function test_show_history_follows_the_chain_when_changes_share_timestamps(): void
    {
        $transaction = $this->createTransaction($this->customer, [[$this->product, 1, '10.00']], TransactionStatus::Refunded);
        $transaction->forceFill(['external_id' => 'order_9', 'source' => TransactionSource::Ingest])->save();

        // Inserted out of order with identical timestamps: only the chain defines the order.
        foreach ([['paid', 'refunded'], [null, 'pending'], ['pending', 'paid']] as [$from, $to]) {
            $transaction->statusChanges()->create([
                'organization_id' => $this->organization->id,
                'from_status' => $from,
                'to_status' => $to,
                'occurred_at' => $transaction->occurred_at,
                'source' => TransactionSource::Ingest,
            ]);
        }

        $response = $this->actingInOrganization($this->owner, $this->organization)
            ->getJson("/api/v1/transactions/{$transaction->id}")
            ->assertOk()
            ->assertJsonPath('data.external_id', 'order_9')
            ->assertJsonPath('data.source', 'ingest');

        $this->assertSame(
            [[null, 'pending'], ['pending', 'paid'], ['paid', 'refunded']],
            array_map(fn (array $change) => [$change['from_status'], $change['to_status']], $response->json('data.status_history')),
        );
    }

    public function test_compact_embeds_do_not_render_unselected_columns(): void
    {
        $this->createTransaction($this->customer, [[$this->product, 1, '10.00']]);

        $this->actingInOrganization($this->owner, $this->organization)
            ->getJson("/api/v1/customers/{$this->customer->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.recent_transactions')
            ->assertJsonMissingPath('data.recent_transactions.0.external_id')
            ->assertJsonMissingPath('data.recent_transactions.0.source')
            ->assertJsonMissingPath('data.recent_transactions.0.status_history');
    }

    public function test_show_returns_404_for_unknown_or_malformed_id(): void
    {
        $this->actingInOrganization($this->owner, $this->organization)
            ->getJson('/api/v1/transactions/0b8f5d0e-0000-4000-8000-000000000000')
            ->assertNotFound();

        $this->getJson('/api/v1/transactions/not-a-uuid')->assertNotFound();
    }

    public function test_show_query_count_does_not_grow_with_items(): void
    {
        $products = Product::factory()->for($this->organization)->count(6)->create();
        $small = $this->createTransaction($this->customer, [[$products[0], 1, '10.00']]);
        $large = $this->createTransaction($this->customer, $products->map(fn (Product $p) => [$p, 1, '10.00'])->all());

        $this->actingInOrganization($this->owner, $this->organization);

        $few = $this->countQueries(fn () => $this->getJson("/api/v1/transactions/{$small->id}")->assertJsonCount(1, 'data.items'));
        $many = $this->countQueries(fn () => $this->getJson("/api/v1/transactions/{$large->id}")->assertJsonCount(6, 'data.items'));

        $this->assertSame($few, $many);
    }

    public function test_member_role_can_read_transactions(): void
    {
        $member = $this->memberOf($this->organization, Organization::ROLE_MEMBER);
        $transaction = $this->createTransaction($this->customer, [[$this->product, 1, '10.00']]);

        $this->actingInOrganization($member, $this->organization)
            ->getJson('/api/v1/transactions')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$transaction->id]);

        $this->getJson("/api/v1/transactions/{$transaction->id}")->assertOk();
    }

    // Read-only

    public function test_write_operations_are_not_exposed(): void
    {
        $transaction = $this->createTransaction($this->customer, [[$this->product, 1, '10.00']]);

        $this->actingInOrganization($this->owner, $this->organization);

        $this->postJson('/api/v1/transactions', ['customer_id' => $this->customer->id])->assertMethodNotAllowed();
        $this->putJson("/api/v1/transactions/{$transaction->id}", ['status' => 'refunded'])->assertMethodNotAllowed();
        $this->patchJson("/api/v1/transactions/{$transaction->id}", ['total_amount' => '1.00'])->assertMethodNotAllowed();
        $this->deleteJson("/api/v1/transactions/{$transaction->id}")->assertMethodNotAllowed();

        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame('paid', $transaction->fresh()->status->value);
        $this->assertSame('10.00', $transaction->fresh()->total_amount);

        $methods = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/transactions'))
            ->flatMap(fn ($route) => $route->methods())
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['GET', 'HEAD'], $methods);
    }

    // Tenant isolation

    public function test_listing_never_includes_other_organization_transactions(): void
    {
        [$foreignTransaction] = $this->foreignTenantTransaction();
        $mine = $this->createTransaction($this->customer, [[$this->product, 1, '10.00']]);

        $this->listTransactions('per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.*.id', [$mine->id]);

        $this->listTransactions("q={$foreignTransaction->id}")
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_show_of_other_organization_transaction_is_not_found(): void
    {
        [$foreignTransaction] = $this->foreignTenantTransaction();

        $this->actingInOrganization($this->owner, $this->organization)
            ->getJson("/api/v1/transactions/{$foreignTransaction->id}")
            ->assertNotFound();
    }

    public function test_customer_id_from_other_organization_returns_no_rows(): void
    {
        [, $foreignCustomer] = $this->foreignTenantTransaction();
        $this->createTransaction($this->customer, [[$this->product, 1, '10.00']]);

        $this->listTransactions("customer_id={$foreignCustomer->id}")
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    public function test_organization_header_without_membership_is_forbidden(): void
    {
        [$foreignTransaction, , $foreignOrganization] = $this->foreignTenantTransaction();

        $this->actingInOrganization($this->owner, $foreignOrganization);

        $this->getJson('/api/v1/transactions')->assertForbidden();
        $this->getJson("/api/v1/transactions/{$foreignTransaction->id}")->assertForbidden();
    }

    // Soft-deleted history

    public function test_soft_deleted_customer_and_product_remain_visible_in_history(): void
    {
        $transaction = $this->createTransaction($this->customer, [[$this->product, 2, '50.00']]);

        $this->customer->delete();
        $this->product->delete();

        $this->actingInOrganization($this->owner, $this->organization)
            ->getJson("/api/v1/transactions/{$transaction->id}")
            ->assertOk()
            ->assertJsonPath('data.customer.id', $this->customer->id)
            ->assertJsonPath('data.customer.name', 'Maria Souza')
            ->assertJsonPath('data.customer.is_deleted', true)
            ->assertJsonPath('data.items.0.product.id', $this->product->id)
            ->assertJsonPath('data.items.0.product.is_deleted', true)
            ->assertJsonPath('data.items.0.line_total', '100.00')
            ->assertJsonPath('data.total_amount', '100.00');

        $this->listTransactions('q=souza')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$transaction->id])
            ->assertJsonPath('data.0.customer.is_deleted', true);

        $this->listTransactions("customer_id={$this->customer->id}")
            ->assertOk()
            ->assertJsonPath('data.*.id', [$transaction->id]);
    }

    // Consistency

    public function test_product_changes_after_the_sale_do_not_alter_the_transaction(): void
    {
        $transaction = $this->createTransaction($this->customer, [[$this->product, 3, '50.00']]);

        $this->product->update(['price' => '80.00', 'name' => 'Renamed later']);

        $this->actingInOrganization($this->owner, $this->organization)
            ->getJson("/api/v1/transactions/{$transaction->id}")
            ->assertOk()
            ->assertJsonPath('data.items.0.unit_price', '50.00')
            ->assertJsonPath('data.items.0.line_total', '150.00')
            ->assertJsonPath('data.items.0.product.name', 'Renamed later')
            ->assertJsonPath('data.total_amount', '150.00');
    }

    public function test_financial_metrics_still_count_only_paid_transactions(): void
    {
        $this->createTransaction($this->customer, [[$this->product, 1, '50.00']]);
        $this->createTransaction($this->customer, [[$this->product, 4, '50.00']], TransactionStatus::Refunded);

        $this->listTransactions()->assertOk()->assertJsonCount(2, 'data');

        $this->getJson("/api/v1/customers/{$this->customer->id}")
            ->assertOk()
            ->assertJsonPath('data.orders_count', 1)
            ->assertJsonPath('data.total_spent', '50.00');

        $this->getJson("/api/v1/products/{$this->product->id}")
            ->assertOk()
            ->assertJsonPath('data.units_sold', 1)
            ->assertJsonPath('data.revenue', '50.00');
    }

    private function listTransactions(string $query = ''): TestResponse
    {
        return $this->actingInOrganization($this->owner, $this->organization)
            ->getJson('/api/v1/transactions'.($query === '' ? '' : "?{$query}"));
    }

    private function createTransactions(int $count): void
    {
        foreach (range(1, $count) as $i) {
            $customer = Customer::factory()->for($this->organization)->create();
            $this->createTransaction($customer, [[$this->product, 1, '10.00'], [$this->product, 2, '5.00']]);
        }
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * @return array{0: Transaction, 1: Customer, 2: Organization}
     */
    private function foreignTenantTransaction(): array
    {
        $organization = Organization::factory()->create();
        $this->memberOf($organization);
        $customer = Customer::factory()->for($organization)->create(['name' => 'Maria Souza B']);
        $product = Product::factory()->for($organization)->create();

        return [$this->createTransaction($customer, [[$product, 1, '10.00']]), $customer, $organization];
    }
}
