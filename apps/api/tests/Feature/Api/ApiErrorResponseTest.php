<?php

namespace Tests\Feature\Api;

use App\Models\Organization;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

class ApiErrorResponseTest extends TestCase
{
    use InteractsWithOrganizationApi;
    use RefreshDatabase;

    public function test_unauthenticated_request_without_json_accept_header_is_a_json_401(): void
    {
        $response = $this->get('/api/v1/products', ['X-Organization-Id' => Organization::factory()->create()->id]);

        $response->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_unknown_api_route_is_a_json_404(): void
    {
        $this->get('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_missing_record_does_not_expose_the_model_class(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->memberOf($organization);

        foreach (['products', 'customers', 'transactions'] as $resource) {
            $this->actingInOrganization($user, $organization)
                ->getJson("/api/v1/{$resource}/00000000-0000-0000-0000-000000000000")
                ->assertNotFound()
                ->assertExactJson(['message' => 'Resource not found.']);
        }
    }

    public function test_record_of_another_organization_is_the_same_generic_404(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->memberOf($organization);
        $foreign = Product::factory()->for(Organization::factory())->create();

        $this->actingInOrganization($user, $organization)
            ->getJson("/api/v1/products/{$foreign->id}")
            ->assertNotFound()
            ->assertExactJson(['message' => 'Resource not found.']);
    }

    public function test_server_errors_hide_details_when_debug_is_off(): void
    {
        config(['app.debug' => false]);

        $this->app['router']->get('/api/v1/__boom', fn () => throw new \RuntimeException('secret detail'));

        $this->get('/api/v1/__boom')
            ->assertStatus(500)
            ->assertExactJson(['message' => 'Server Error']);
    }
}
