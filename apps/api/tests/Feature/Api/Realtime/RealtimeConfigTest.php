<?php

namespace Tests\Feature\Api\Realtime;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

/**
 * GET /api/v1/realtime tells the clients where to open the WebSocket, so neither the web build
 * nor the mobile build carries Reverb settings.
 */
class RealtimeConfigTest extends TestCase
{
    use InteractsWithOrganizationApi, RefreshDatabase;

    public function test_it_describes_the_public_reverb_endpoint(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'public-key',
            'broadcasting.connections.reverb.secret' => 'never-exposed',
            'reverb.public' => ['host' => 'api.example.test', 'port' => 443, 'scheme' => 'https'],
        ]);

        $this->actingAs($this->memberOf(Organization::factory()->create()))
            ->getJson('/api/v1/realtime')
            ->assertOk()
            ->assertExactJson(['data' => [
                'enabled' => true,
                'key' => 'public-key',
                'host' => 'api.example.test',
                'port' => 443,
                'scheme' => 'https',
                'auth_endpoint' => '/api/v1/broadcasting/auth',
            ]]);
    }

    public function test_it_is_disabled_without_reverb(): void
    {
        config(['broadcasting.default' => 'null']);

        $this->actingAs($this->memberOf(Organization::factory()->create()))
            ->getJson('/api/v1/realtime')
            ->assertOk()
            ->assertExactJson(['data' => ['enabled' => false]]);
    }

    public function test_a_guest_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/realtime')->assertUnauthorized();
    }
}
