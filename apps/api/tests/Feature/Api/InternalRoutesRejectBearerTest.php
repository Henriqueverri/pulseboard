<?php

namespace Tests\Feature\Api;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

/**
 * Internal routes are SPA-only (session cookie + CSRF). A bearer token must be
 * a plain 401, never a lookup of Sanctum personal access tokens (there is no such table).
 */
class InternalRoutesRejectBearerTest extends TestCase
{
    use InteractsWithOrganizationApi, RefreshDatabase;

    /**
     * @return array<string, array{0: bool}>
     */
    public static function origins(): array
    {
        return [
            'stateful (SPA origin)' => [true],
            'external (no origin)' => [false],
        ];
    }

    #[DataProvider('origins')]
    public function test_bearer_token_on_an_internal_route_is_a_401(bool $stateful): void
    {
        $organization = Organization::factory()->create();

        $this->withoutOriginUnless($stateful)
            ->withHeaders([
                'Authorization' => 'Bearer pb_Ab12Cd34Ef56_not-a-real-secret',
                'X-Organization-Id' => $organization->id,
            ])
            ->getJson('/api/v1/products')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    #[DataProvider('origins')]
    public function test_bearer_token_on_the_insights_routes_is_a_401(bool $stateful): void
    {
        $organization = Organization::factory()->create();
        $headers = [
            'Authorization' => 'Bearer pb_Ab12Cd34Ef56_not-a-real-secret',
            'X-Organization-Id' => $organization->id,
        ];

        $this->withoutOriginUnless($stateful)
            ->withHeaders($headers)
            ->getJson('/api/v1/insights/period-summary')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->withoutOriginUnless($stateful)
            ->withHeaders($headers)
            ->postJson('/api/v1/insights/period-summary')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_bearer_token_does_not_replace_or_break_a_valid_session(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->memberOf($organization);

        $this->actingInOrganization($owner, $organization)
            ->withHeader('Authorization', 'Bearer 1|some-sanctum-looking-token')
            ->getJson('/api/v1/products')
            ->assertOk();
    }

    private function withoutOriginUnless(bool $stateful): static
    {
        if (! $stateful) {
            $this->withoutHeaders(['Origin', 'Referer']);
        }

        return $this;
    }
}
