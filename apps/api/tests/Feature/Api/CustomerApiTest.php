<?php

namespace Tests\Feature\Api;

use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

class CustomerApiTest extends TestCase
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
            ->getJson('/api/v1/customers')
            ->assertUnauthorized();
    }

    public function test_index_returns_paginated_customers_sorted_by_name(): void
    {
        foreach (['Carla', 'Ana', 'Bruno'] as $name) {
            Customer::factory()->for($this->organization)->create(['name' => $name]);
        }

        $this->actingInOrganization($this->owner, $this->organization)
            ->getJson('/api/v1/customers')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'email', 'created_at', 'updated_at']],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'per_page', 'total', 'last_page'],
            ])
            ->assertJsonPath('data.*.name', ['Ana', 'Bruno', 'Carla'])
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonMissingPath('data.0.organization_id')
            ->assertJsonMissingPath('data.0.orders_count');
    }

    public function test_index_honours_page_and_per_page(): void
    {
        Customer::factory()->for($this->organization)->count(5)->create();

        $this->actingInOrganization($this->owner, $this->organization)
            ->getJson('/api/v1/customers?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.total', 5);
    }

    public function test_index_rejects_invalid_query_parameters(): void
    {
        $this->actingInOrganization($this->owner, $this->organization)
            ->getJson('/api/v1/customers?per_page=0&page=abc')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page', 'page']);
    }

    public function test_index_searches_name_and_email_case_insensitively(): void
    {
        $maria = Customer::factory()->for($this->organization)->create(['name' => 'Maria Souza', 'email' => 'maria@example.com']);
        $joao = Customer::factory()->for($this->organization)->create(['name' => 'João Lima', 'email' => 'jlima@corp.example']);
        Customer::factory()->for($this->organization)->create(['name' => 'Pedro', 'email' => 'pedro@example.com']);

        $this->actingInOrganization($this->owner, $this->organization)
            ->getJson('/api/v1/customers?q=SOUZA')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$maria->id]);

        $this->getJson('/api/v1/customers?q=corp.example')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$joao->id]);
    }

    public function test_index_hides_deleted_customers(): void
    {
        $visible = Customer::factory()->for($this->organization)->create();
        Customer::factory()->for($this->organization)->create()->delete();

        $this->actingInOrganization($this->owner, $this->organization)
            ->getJson('/api/v1/customers')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$visible->id]);
    }

    public function test_store_creates_customer_in_current_organization(): void
    {
        $response = $this->actingInOrganization($this->owner, $this->organization)
            ->postJson('/api/v1/customers', [
                'name' => 'Ana Paula',
                'email' => '  Ana.Paula@Example.COM ',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Ana Paula')
            ->assertJsonPath('data.email', 'ana.paula@example.com');

        $this->assertDatabaseHas('customers', [
            'id' => $response->json('data.id'),
            'organization_id' => $this->organization->id,
            'email' => 'ana.paula@example.com',
        ]);
    }

    public function test_store_validates_payload(): void
    {
        $this->actingInOrganization($this->owner, $this->organization)
            ->postJson('/api/v1/customers', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email']);

        $this->postJson('/api/v1/customers', ['name' => str_repeat('a', 256), 'email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email']);
    }

    public function test_email_must_be_unique_within_the_organization_ignoring_case(): void
    {
        Customer::factory()->for($this->organization)->create(['email' => 'dup@example.com']);

        $this->actingInOrganization($this->owner, $this->organization)
            ->postJson('/api/v1/customers', ['name' => 'Copy', 'email' => 'DUP@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_same_email_is_allowed_in_different_organizations(): void
    {
        Customer::factory()->for(Organization::factory())->create(['email' => 'shared@example.com']);

        $this->actingInOrganization($this->owner, $this->organization)
            ->postJson('/api/v1/customers', ['name' => 'Mine', 'email' => 'shared@example.com'])
            ->assertCreated();

        $this->assertSame(2, Customer::query()->where('email', 'shared@example.com')->count());
    }

    public function test_email_of_a_deleted_customer_stays_reserved(): void
    {
        Customer::factory()->for($this->organization)->create(['email' => 'gone@example.com'])->delete();

        $this->actingInOrganization($this->owner, $this->organization)
            ->postJson('/api/v1/customers', ['name' => 'Reuse', 'email' => 'gone@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_show_returns_customer_with_paid_metrics_and_recent_transactions(): void
    {
        $customer = Customer::factory()->for($this->organization)->create();
        $otherCustomer = Customer::factory()->for($this->organization)->create();
        $product = Product::factory()->for($this->organization)->create();

        $paid = [];
        foreach (range(1, 5) as $day) {
            $paid[$day] = $this->createTransaction($customer, [[$product, 1, '10.00']], occurredAt: "2026-09-0{$day} 12:00:00");
        }
        $refunded = $this->createTransaction($customer, [[$product, 3, '100.00']], TransactionStatus::Refunded, '2026-09-10 12:00:00');
        $this->createTransaction($otherCustomer, [[$product, 1, '999.00']]);

        $response = $this->actingInOrganization($this->owner, $this->organization)
            ->getJson("/api/v1/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $customer->id)
            ->assertJsonPath('data.orders_count', 5)
            ->assertJsonPath('data.total_spent', '50.00')
            ->assertJsonCount(5, 'data.recent_transactions')
            ->assertJsonStructure(['data' => ['recent_transactions' => [['id', 'status', 'total_amount', 'occurred_at']]]]);

        $this->assertSame(
            [$refunded->id, $paid[5]->id, $paid[4]->id, $paid[3]->id, $paid[2]->id],
            $response->json('data.recent_transactions.*.id'),
        );
        $this->assertSame(['id', 'status', 'total_amount', 'occurred_at'], array_keys($response->json('data.recent_transactions.0')));
        $this->assertSame('refunded', $response->json('data.recent_transactions.0.status'));
        $this->assertSame('300.00', $response->json('data.recent_transactions.0.total_amount'));
    }

    public function test_show_returns_zero_metrics_for_customer_without_transactions(): void
    {
        $customer = Customer::factory()->for($this->organization)->create();

        $this->actingInOrganization($this->owner, $this->organization)
            ->getJson("/api/v1/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.orders_count', 0)
            ->assertJsonPath('data.total_spent', '0.00')
            ->assertJsonPath('data.recent_transactions', []);
    }

    public function test_update_changes_allowed_fields(): void
    {
        $customer = Customer::factory()->for($this->organization)->create(['email' => 'old@example.com']);

        $this->actingInOrganization($this->owner, $this->organization)
            ->patchJson("/api/v1/customers/{$customer->id}", ['name' => 'Renamed', 'email' => 'NEW@example.com'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.email', 'new@example.com');

        $this->patchJson("/api/v1/customers/{$customer->id}", ['email' => 'new@example.com'])
            ->assertOk();
    }

    public function test_update_validates_payload_and_email_uniqueness(): void
    {
        Customer::factory()->for($this->organization)->create(['email' => 'taken@example.com']);
        $customer = Customer::factory()->for($this->organization)->create();

        $this->actingInOrganization($this->owner, $this->organization)
            ->patchJson("/api/v1/customers/{$customer->id}", ['name' => '', 'email' => 'taken@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email']);
    }

    public function test_update_cannot_change_id_or_organization(): void
    {
        $customer = Customer::factory()->for($this->organization)->create(['name' => 'Stay']);
        $foreign = Organization::factory()->create();

        $this->actingInOrganization($this->owner, $this->organization)
            ->patchJson("/api/v1/customers/{$customer->id}", [
                'id' => '0b8f5d0e-0000-4000-8000-000000000000',
                'organization_id' => $foreign->id,
                'name' => 'Moved',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['id', 'organization_id']);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'organization_id' => $this->organization->id,
            'name' => 'Stay',
        ]);
    }

    public function test_delete_without_transactions_removes_the_customer(): void
    {
        $customer = Customer::factory()->for($this->organization)->create();

        $this->actingInOrganization($this->owner, $this->organization)
            ->deleteJson("/api/v1/customers/{$customer->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
    }

    public function test_delete_with_transactions_soft_deletes_and_keeps_history(): void
    {
        $customer = Customer::factory()->for($this->organization)->create();
        $product = Product::factory()->for($this->organization)->create();
        $transaction = $this->createTransaction($customer, [[$product, 1, '10.00']]);

        $this->actingInOrganization($this->owner, $this->organization)
            ->deleteJson("/api/v1/customers/{$customer->id}")
            ->assertNoContent();

        $this->assertSoftDeleted($customer);
        $this->assertTrue(Transaction::query()->whereKey($transaction->id)->exists());
        $this->assertTrue($transaction->fresh()->customer->is($customer));

        $this->getJson("/api/v1/customers/{$customer->id}")->assertNotFound();
    }

    public function test_member_can_manage_but_not_delete_customers(): void
    {
        $member = $this->memberOf($this->organization, Organization::ROLE_MEMBER);
        $customer = Customer::factory()->for($this->organization)->create();

        $this->actingInOrganization($member, $this->organization)
            ->postJson('/api/v1/customers', ['name' => 'By member', 'email' => 'member-made@example.com'])
            ->assertCreated();

        $this->patchJson("/api/v1/customers/{$customer->id}", ['name' => 'Edited by member'])
            ->assertOk();

        $this->deleteJson("/api/v1/customers/{$customer->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'deleted_at' => null]);
    }
}
