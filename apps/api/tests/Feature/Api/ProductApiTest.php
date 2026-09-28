<?php

namespace Tests\Feature\Api;

use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use InteractsWithOrganizationApi, RefreshDatabase;

    private Organization $organization;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->owner = $this->memberOf($this->organization);
    }

    public function test_guest_receives_401(): void
    {
        $this->withHeader('X-Organization-Id', $this->organization->id)
            ->getJson('/api/v1/products')
            ->assertUnauthorized();
    }

    public function test_index_returns_paginated_products_sorted_by_name(): void
    {
        foreach (['Charlie', 'Alpha', 'Bravo'] as $name) {
            Product::factory()->for($this->organization)->create(['name' => $name]);
        }

        $this->actingInOrganization($this->owner, $this->organization)
            ->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'sku', 'price', 'status', 'created_at', 'updated_at']],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'per_page', 'total', 'last_page'],
            ])
            ->assertJsonPath('data.*.name', ['Alpha', 'Bravo', 'Charlie'])
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonMissingPath('data.0.organization_id')
            ->assertJsonMissingPath('data.0.units_sold');
    }

    public function test_index_honours_page_and_per_page(): void
    {
        Product::factory()->for($this->organization)->count(5)->create();

        $response = $this->actingInOrganization($this->owner, $this->organization)
            ->getJson('/api/v1/products?per_page=2&page=3')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.total', 5);

        $this->assertStringContainsString('per_page=2', $response->json('links.first'));
    }

    public function test_index_rejects_invalid_query_parameters(): void
    {
        $this->actingInOrganization($this->owner, $this->organization)
            ->getJson('/api/v1/products?per_page=101&status=archived&page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page', 'status', 'page']);
    }

    public function test_index_searches_name_and_sku_case_insensitively(): void
    {
        $mouse = Product::factory()->for($this->organization)->create(['name' => 'Wireless Mouse', 'sku' => 'MS-001']);
        $keyboard = Product::factory()->for($this->organization)->create(['name' => 'Keyboard', 'sku' => 'KB-SPECIAL']);
        Product::factory()->for($this->organization)->create(['name' => 'Monitor', 'sku' => 'MN-001']);

        $this->actingInOrganization($this->owner, $this->organization)
            ->getJson('/api/v1/products?q=mouse')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$mouse->id]);

        $this->getJson('/api/v1/products?q=kb-spec')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$keyboard->id]);
    }

    public function test_index_filters_by_status(): void
    {
        Product::factory()->for($this->organization)->count(2)->create();
        $inactive = Product::factory()->for($this->organization)->inactive()->create();

        $this->actingInOrganization($this->owner, $this->organization)
            ->getJson('/api/v1/products?status=inactive')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$inactive->id])
            ->assertJsonPath('data.0.status', 'inactive');
    }

    public function test_index_hides_deleted_products(): void
    {
        $visible = Product::factory()->for($this->organization)->create();
        Product::factory()->for($this->organization)->create()->delete();

        $this->actingInOrganization($this->owner, $this->organization)
            ->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$visible->id]);
    }

    public function test_store_creates_product_in_current_organization(): void
    {
        $response = $this->actingInOrganization($this->owner, $this->organization)
            ->postJson('/api/v1/products', [
                'name' => 'Hub USB-C',
                'sku' => 'HUB-01',
                'price' => 199.9,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Hub USB-C')
            ->assertJsonPath('data.sku', 'HUB-01')
            ->assertJsonPath('data.price', '199.90')
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('products', [
            'id' => $response->json('data.id'),
            'organization_id' => $this->organization->id,
            'status' => 'active',
        ]);
    }

    public function test_store_accepts_explicit_status_and_missing_sku(): void
    {
        $this->actingInOrganization($this->owner, $this->organization)
            ->postJson('/api/v1/products', ['name' => 'No SKU A', 'price' => '10', 'status' => 'inactive'])
            ->assertCreated()
            ->assertJsonPath('data.sku', null)
            ->assertJsonPath('data.status', 'inactive');

        $this->postJson('/api/v1/products', ['name' => 'No SKU B', 'price' => '10', 'sku' => ''])
            ->assertCreated()
            ->assertJsonPath('data.sku', null);
    }

    public function test_store_validates_payload(): void
    {
        $this->actingInOrganization($this->owner, $this->organization)
            ->postJson('/api/v1/products', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'price']);

        $this->postJson('/api/v1/products', [
            'name' => str_repeat('a', 256),
            'sku' => str_repeat('s', 65),
            'price' => -1,
            'status' => 'archived',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'sku', 'price', 'status']);

        $this->postJson('/api/v1/products', ['name' => 'Cable', 'price' => '10.999'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price']);

        $this->postJson('/api/v1/products', ['name' => 'Cable', 'price' => 'free'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price']);
    }

    public function test_sku_must_be_unique_within_the_organization(): void
    {
        Product::factory()->for($this->organization)->create(['sku' => 'DUP-1']);

        $this->actingInOrganization($this->owner, $this->organization)
            ->postJson('/api/v1/products', ['name' => 'Copy', 'sku' => 'DUP-1', 'price' => 10])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sku']);
    }

    public function test_same_sku_is_allowed_in_different_organizations(): void
    {
        Product::factory()->for(Organization::factory())->create(['sku' => 'SHARED-1']);

        $this->actingInOrganization($this->owner, $this->organization)
            ->postJson('/api/v1/products', ['name' => 'Mine', 'sku' => 'SHARED-1', 'price' => 10])
            ->assertCreated();

        $this->assertSame(2, Product::query()->where('sku', 'SHARED-1')->count());
    }

    public function test_sku_of_a_deleted_product_stays_reserved(): void
    {
        $archived = Product::factory()->for($this->organization)->create(['sku' => 'OLD-1']);
        $archived->delete();

        $this->actingInOrganization($this->owner, $this->organization)
            ->postJson('/api/v1/products', ['name' => 'Reuse', 'sku' => 'OLD-1', 'price' => 10])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sku']);
    }

    public function test_show_returns_product_with_paid_sales_metrics(): void
    {
        $product = Product::factory()->for($this->organization)->create(['price' => '50.00']);
        $other = Product::factory()->for($this->organization)->create();
        $customer = Customer::factory()->for($this->organization)->create();

        $this->createTransaction($customer, [[$product, 2, '50.00'], [$other, 5, '10.00']]);
        $this->createTransaction($customer, [[$product, 1, '45.50']]);
        $this->createTransaction($customer, [[$product, 10, '50.00']], TransactionStatus::Refunded);
        $this->createTransaction($customer, [[$product, 10, '50.00']], TransactionStatus::Pending);

        $this->actingInOrganization($this->owner, $this->organization)
            ->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.price', '50.00')
            ->assertJsonPath('data.units_sold', 3)
            ->assertJsonPath('data.revenue', '145.50');
    }

    public function test_show_returns_zero_metrics_for_unsold_product(): void
    {
        $product = Product::factory()->for($this->organization)->create();

        $this->actingInOrganization($this->owner, $this->organization)
            ->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.units_sold', 0)
            ->assertJsonPath('data.revenue', '0.00');
    }

    public function test_show_returns_404_for_unknown_or_malformed_id(): void
    {
        $this->actingInOrganization($this->owner, $this->organization)
            ->getJson('/api/v1/products/0b8f5d0e-0000-4000-8000-000000000000')
            ->assertNotFound();

        $this->getJson('/api/v1/products/not-a-uuid')->assertNotFound();
    }

    public function test_update_changes_allowed_fields(): void
    {
        $product = Product::factory()->for($this->organization)->create(['sku' => 'KEEP-1']);

        $this->actingInOrganization($this->owner, $this->organization)
            ->patchJson("/api/v1/products/{$product->id}", [
                'name' => 'Renamed',
                'price' => '12.30',
                'status' => 'inactive',
                'sku' => 'KEEP-1',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.price', '12.30')
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.sku', 'KEEP-1');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Renamed', 'status' => 'inactive']);
    }

    public function test_update_is_partial(): void
    {
        $product = Product::factory()->for($this->organization)->create(['name' => 'Original', 'price' => '10.00']);

        $this->actingInOrganization($this->owner, $this->organization)
            ->patchJson("/api/v1/products/{$product->id}", ['price' => '11.00'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Original')
            ->assertJsonPath('data.price', '11.00');
    }

    public function test_update_validates_payload_and_sku_uniqueness(): void
    {
        Product::factory()->for($this->organization)->create(['sku' => 'TAKEN']);
        $product = Product::factory()->for($this->organization)->create();

        $this->actingInOrganization($this->owner, $this->organization)
            ->patchJson("/api/v1/products/{$product->id}", ['name' => '', 'price' => -5, 'sku' => 'TAKEN'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'price', 'sku']);
    }

    public function test_update_cannot_change_id_or_organization(): void
    {
        $product = Product::factory()->for($this->organization)->create(['name' => 'Stay']);
        $foreign = Organization::factory()->create();

        $this->actingInOrganization($this->owner, $this->organization)
            ->patchJson("/api/v1/products/{$product->id}", [
                'id' => '0b8f5d0e-0000-4000-8000-000000000000',
                'organization_id' => $foreign->id,
                'name' => 'Moved',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['id', 'organization_id']);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'organization_id' => $this->organization->id,
            'name' => 'Stay',
        ]);
    }

    public function test_put_behaves_like_patch(): void
    {
        $product = Product::factory()->for($this->organization)->create();

        $this->actingInOrganization($this->owner, $this->organization)
            ->putJson("/api/v1/products/{$product->id}", ['name' => 'Via PUT'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Via PUT');
    }

    public function test_delete_without_sales_removes_the_product(): void
    {
        $product = Product::factory()->for($this->organization)->create();

        $this->actingInOrganization($this->owner, $this->organization)
            ->deleteJson("/api/v1/products/{$product->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_delete_with_sales_soft_deletes_and_keeps_history(): void
    {
        $product = Product::factory()->for($this->organization)->create();
        $customer = Customer::factory()->for($this->organization)->create();
        $transaction = $this->createTransaction($customer, [[$product, 2, '30.00']]);

        $this->actingInOrganization($this->owner, $this->organization)
            ->deleteJson("/api/v1/products/{$product->id}")
            ->assertNoContent();

        $this->assertSoftDeleted($product);
        $this->assertSame(1, TransactionItem::query()->where('product_id', $product->id)->count());
        $this->assertSame('60.00', $transaction->fresh()->total_amount);

        $this->getJson("/api/v1/products/{$product->id}")->assertNotFound();
        $this->deleteJson("/api/v1/products/{$product->id}")->assertNotFound();
    }

    public function test_member_can_manage_but_not_delete_products(): void
    {
        $member = $this->memberOf($this->organization, Organization::ROLE_MEMBER);
        $product = Product::factory()->for($this->organization)->create();

        $this->actingInOrganization($member, $this->organization)
            ->postJson('/api/v1/products', ['name' => 'By member', 'price' => 5])
            ->assertCreated();

        $this->patchJson("/api/v1/products/{$product->id}", ['name' => 'Edited by member'])
            ->assertOk();

        $this->deleteJson("/api/v1/products/{$product->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'deleted_at' => null]);
    }
}
