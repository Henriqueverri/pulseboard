<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

class ApiTenantIsolationTest extends TestCase
{
    use InteractsWithOrganizationApi, RefreshDatabase;

    private Organization $organizationA;

    private Organization $organizationB;

    private User $userA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizationA = Organization::factory()->create();
        $this->organizationB = Organization::factory()->create();
        $this->userA = $this->memberOf($this->organizationA);
        $this->memberOf($this->organizationB);

        Product::factory()->for($this->organizationA)->count(3)->create();
        Product::factory()->for($this->organizationB)->count(4)->create();
        Customer::factory()->for($this->organizationA)->count(3)->create();
        Customer::factory()->for($this->organizationB)->count(4)->create();
    }

    /**
     * @return array<string, array{0: string, 1: class-string<Model>, 2: array<string, mixed>}>
     */
    public static function resources(): array
    {
        return [
            'products' => ['products', Product::class, ['name' => 'Injected', 'sku' => 'INJ-1', 'price' => 10]],
            'customers' => ['customers', Customer::class, ['name' => 'Injected', 'email' => 'injected@example.com']],
        ];
    }

    /**
     * Scenario A: the header points to an organization the user is not a member of.
     *
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('resources')]
    public function test_foreign_organization_header_is_forbidden(string $uri, string $model, array $payload): void
    {
        $foreignRecord = $model::query()->forOrganization($this->organizationB)->firstOrFail();

        $this->actingInOrganization($this->userA, $this->organizationB);

        $this->getJson("/api/v1/{$uri}")->assertForbidden();
        $this->postJson("/api/v1/{$uri}", $payload)->assertForbidden();
        $this->getJson("/api/v1/{$uri}/{$foreignRecord->id}")->assertForbidden();
        $this->patchJson("/api/v1/{$uri}/{$foreignRecord->id}", ['name' => 'Hijacked'])->assertForbidden();
        $this->deleteJson("/api/v1/{$uri}/{$foreignRecord->id}")->assertForbidden();

        $this->assertDatabaseMissing($uri, ['name' => 'Injected']);
        $this->assertDatabaseHas($uri, ['id' => $foreignRecord->id, 'name' => $foreignRecord->name, 'deleted_at' => null]);
    }

    /**
     * Scenario B: valid context for organization A, but the record belongs to B.
     *
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('resources')]
    public function test_record_from_another_organization_is_not_found(string $uri, string $model, array $payload): void
    {
        $foreignRecord = $model::query()->forOrganization($this->organizationB)->firstOrFail();

        $this->actingInOrganization($this->userA, $this->organizationA);

        $this->getJson("/api/v1/{$uri}/{$foreignRecord->id}")->assertNotFound();
        $this->patchJson("/api/v1/{$uri}/{$foreignRecord->id}", ['name' => 'Hijacked'])->assertNotFound();
        $this->deleteJson("/api/v1/{$uri}/{$foreignRecord->id}")->assertNotFound();

        $this->assertDatabaseHas($uri, ['id' => $foreignRecord->id, 'name' => $foreignRecord->name, 'deleted_at' => null]);
    }

    /**
     * Scenario C: listings never include rows from another organization, even when searching for them.
     *
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('resources')]
    public function test_listing_only_returns_current_organization_records(string $uri, string $model, array $payload): void
    {
        $ownIds = $model::query()->forOrganization($this->organizationA)->pluck('id')->sort()->values()->all();
        $foreignRecord = $model::query()->forOrganization($this->organizationB)->firstOrFail();

        $this->actingInOrganization($this->userA, $this->organizationA);

        $response = $this->getJson("/api/v1/{$uri}?per_page=100")
            ->assertOk()
            ->assertJsonPath('meta.total', 3);

        $this->assertSame($ownIds, collect($response->json('data.*.id'))->sort()->values()->all());

        $this->getJson("/api/v1/{$uri}?q=".urlencode($foreignRecord->name))
            ->assertOk()
            ->assertJsonMissing(['id' => $foreignRecord->id]);
    }

    /**
     * Scenario D: organization_id in the payload is rejected; the tenant always comes from the context.
     *
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('resources')]
    public function test_organization_id_in_payload_is_rejected(string $uri, string $model, array $payload): void
    {
        $this->actingInOrganization($this->userA, $this->organizationA);

        $this->postJson("/api/v1/{$uri}", [...$payload, 'organization_id' => $this->organizationB->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['organization_id']);

        $this->assertDatabaseMissing($uri, ['name' => 'Injected']);

        $created = $this->postJson("/api/v1/{$uri}", $payload)->assertCreated();

        $this->assertDatabaseHas($uri, [
            'id' => $created->json('data.id'),
            'organization_id' => $this->organizationA->id,
        ]);
    }
}
