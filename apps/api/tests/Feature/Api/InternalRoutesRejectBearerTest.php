<?php

namespace Tests\Feature\Api;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

/**
 * Internal routes accept a session (SPA) or a Sanctum personal access token (native
 * clients). Any other bearer, including an integration API key (pb_...), is a plain
 * 401: it is not the id|secret format and matches no stored token hash.
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

    /**
     * @return array<string, array{0: bool, 1: string}>
     */
    public static function rejectedBearers(): array
    {
        $bearers = [
            'API key' => 'pb_Ab12Cd34Ef56_not-a-real-secret',
            'token-looking garbage' => '1|some-sanctum-looking-token',
            'unknown token id' => '999|pbm_'.str_repeat('a', 48),
            'non-numeric id' => 'abc|pbm_'.str_repeat('a', 48),
        ];

        $cases = [];

        foreach (self::origins() as $origin => [$stateful]) {
            foreach ($bearers as $name => $bearer) {
                $cases["{$name}, {$origin}"] = [$stateful, $bearer];
            }
        }

        return $cases;
    }

    #[DataProvider('rejectedBearers')]
    public function test_bearer_that_is_not_an_access_token_is_a_401(bool $stateful, string $bearer): void
    {
        $organization = Organization::factory()->create();
        $this->memberOf($organization)->createToken('Pixel 8', ['*'], now()->addDay());

        $this->withoutOriginUnless($stateful)
            ->withHeaders([
                'Authorization' => "Bearer {$bearer}",
                'X-Organization-Id' => $organization->id,
            ])
            ->getJson('/api/v1/products')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    #[DataProvider('origins')]
    public function test_api_key_bearer_on_the_insights_routes_is_a_401(bool $stateful): void
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

    #[DataProvider('origins')]
    public function test_personal_access_token_authenticates_internal_routes(bool $stateful): void
    {
        $organization = Organization::factory()->create();
        $user = $this->memberOf($organization);
        $token = $user->createToken('Pixel 8', ['*'], now()->addDay())->plainTextToken;

        $this->withoutOriginUnless($stateful)
            ->withHeaders([
                'Authorization' => "Bearer {$token}",
                'X-Organization-Id' => $organization->id,
            ])
            ->getJson('/api/v1/products')
            ->assertOk();

        Auth::forgetGuards();

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_invalid_bearer_does_not_replace_or_break_a_valid_session(): void
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
