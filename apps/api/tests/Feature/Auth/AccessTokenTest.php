<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

/**
 * Personal access tokens for native clients: no SPA origin, no session, no CSRF,
 * only Authorization: Bearer plus X-Organization-Id on tenant routes.
 */
class AccessTokenTest extends TestCase
{
    use InteractsWithOrganizationApi, RefreshDatabase;

    // UserFactory's password.
    private const PASSWORD = 'password';

    private const DEVICE = 'Pixel 8 · a1b2';

    public function test_valid_credentials_issue_a_30_day_token_without_starting_a_session(): void
    {
        $this->freezeSecond();
        $organization = Organization::factory()->create(['name' => 'Acme']);
        $user = $this->memberOf($organization);

        $response = $this->asNativeClient()
            ->postJson('/api/v1/auth/tokens', $this->credentials($user))
            ->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('expires_at', now()->addDays(30)->toJSON())
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('organizations.0.id', $organization->id)
            ->assertJsonPath('organizations.0.role', Organization::ROLE_OWNER)
            ->assertJsonPath('current_organization.id', $organization->id)
            ->assertJsonMissingPath('user.password');

        $this->assertMatchesRegularExpression('/^\d+\|pbm_[A-Za-z0-9]{48}$/', $response->json('token'));
        $this->assertGuest('web');

        $token = PersonalAccessToken::query()->sole();
        $this->assertSame(self::DEVICE, $token->name);
        $this->assertTrue($token->tokenable->is($user));
        $this->assertSame(['*'], $token->abilities);
        $this->assertTrue($token->expires_at->equalTo(now()->addDays(30)));
        $this->assertNotSame($response->json('token'), $token->token);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidCredentials(): array
    {
        return [
            'wrong password' => ['ada@example.com', 'wrong-password'],
            'unknown email' => ['nobody@example.com', self::PASSWORD],
        ];
    }

    #[DataProvider('invalidCredentials')]
    public function test_invalid_credentials_are_the_same_generic_422(string $email, string $password): void
    {
        User::factory()->create(['email' => 'ada@example.com', 'password' => self::PASSWORD]);

        $this->asNativeClient()
            ->postJson('/api/v1/auth/tokens', ['email' => $email, 'password' => $password, 'device_name' => self::DEVICE])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The provided credentials are incorrect.',
                'errors' => ['email' => ['The provided credentials are incorrect.']],
            ]);

        $this->assertSame(0, PersonalAccessToken::query()->count());
        $this->assertGuest('web');
    }

    public function test_device_name_is_required_and_limited_to_100_characters(): void
    {
        $user = $this->memberOf(Organization::factory()->create());

        foreach ([null, '', str_repeat('a', 101)] as $deviceName) {
            $this->asNativeClient()
                ->postJson('/api/v1/auth/tokens', [...$this->credentials($user), 'device_name' => $deviceName])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['device_name']);
        }

        $this->assertSame(0, PersonalAccessToken::query()->count());

        $this->asNativeClient()
            ->postJson('/api/v1/auth/tokens', [...$this->credentials($user), 'device_name' => str_repeat('a', 100)])
            ->assertCreated();
    }

    public function test_issuing_is_throttled_like_the_login(): void
    {
        $user = $this->memberOf(Organization::factory()->create());
        $attempt = [...$this->credentials($user), 'password' => 'wrong-password'];

        for ($i = 0; $i < 6; $i++) {
            $this->asNativeClient()->postJson('/api/v1/auth/tokens', $attempt)->assertUnprocessable();
        }

        $this->asNativeClient()->postJson('/api/v1/auth/tokens', $attempt)->assertTooManyRequests();
    }

    public function test_a_new_token_for_the_same_device_replaces_the_previous_one(): void
    {
        $user = $this->memberOf(Organization::factory()->create());

        $first = $this->issueToken($user);
        $second = $this->issueToken($user);

        $this->assertSame(1, $user->tokens()->count());
        $this->asNativeClient($first)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->asNativeClient($second)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_tokens_of_different_devices_coexist(): void
    {
        $user = $this->memberOf(Organization::factory()->create());
        $other = $this->memberOf(Organization::factory()->create());

        $phone = $this->issueToken($user, 'Pixel 8 · a1b2');
        $tablet = $this->issueToken($user, 'Galaxy Tab · c3d4');
        $otherPhone = $this->issueToken($other, 'Pixel 8 · a1b2');

        $this->assertSame(2, $user->tokens()->count());
        $this->asNativeClient($phone)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.id', $user->id);
        $this->asNativeClient($tablet)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.id', $user->id);
        $this->asNativeClient($otherPhone)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.id', $other->id);
    }

    public function test_issuing_a_token_prunes_the_users_expired_tokens(): void
    {
        $user = $this->memberOf(Organization::factory()->create());
        $other = $this->memberOf(Organization::factory()->create());
        $user->createToken('Old phone', ['*'], now()->subMinute());
        $user->createToken('Tablet', ['*'], now()->addDay());
        $other->createToken('Old phone', ['*'], now()->subMinute());

        $this->issueToken($user);

        $this->assertEqualsCanonicalizing(['Tablet', self::DEVICE], $user->tokens()->pluck('name')->all());
        $this->assertSame(1, $other->tokens()->count());
    }

    public function test_token_authenticates_me(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->memberOf($organization, Organization::ROLE_MEMBER);
        $token = $this->issueToken($user);

        $this->asNativeClient($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('organizations.0.role', Organization::ROLE_MEMBER)
            ->assertJsonPath('current_organization.id', $organization->id);

        $this->assertNotNull(PersonalAccessToken::query()->sole()->last_used_at);
    }

    public function test_token_with_organization_header_reads_the_dashboard(): void
    {
        $organization = Organization::factory()->create();
        $token = $this->issueToken($this->memberOf($organization, Organization::ROLE_MEMBER));

        $this->asNativeClient($token)
            ->withHeader('X-Organization-Id', $organization->id)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonStructure(['data' => ['revenue', 'orders', 'average_order_value', 'customers']]);
    }

    public function test_token_without_organization_header_is_a_400(): void
    {
        $token = $this->issueToken($this->memberOf(Organization::factory()->create()));

        $this->asNativeClient($token)
            ->getJson('/api/v1/dashboard')
            ->assertStatus(400)
            ->assertExactJson(['message' => 'The X-Organization-Id header is required.']);
    }

    public function test_token_on_an_organization_without_membership_is_a_403(): void
    {
        $token = $this->issueToken($this->memberOf(Organization::factory()->create()));
        $foreign = Organization::factory()->create();

        $this->asNativeClient($token)
            ->withHeader('X-Organization-Id', $foreign->id)
            ->getJson('/api/v1/dashboard')
            ->assertForbidden()
            ->assertExactJson(['message' => 'You do not have access to this organization.']);
    }

    public function test_policies_apply_to_token_requests(): void
    {
        $organization = Organization::factory()->create();
        $product = Product::factory()->for($organization)->create();
        $member = $this->issueToken($this->memberOf($organization, Organization::ROLE_MEMBER));
        $owner = $this->issueToken($this->memberOf($organization));

        $this->asNativeClient($member)
            ->withHeader('X-Organization-Id', $organization->id)
            ->deleteJson("/api/v1/products/{$product->id}")
            ->assertForbidden();

        $this->assertModelExists($product);

        $this->asNativeClient($owner)
            ->withHeader('X-Organization-Id', $organization->id)
            ->deleteJson("/api/v1/products/{$product->id}")
            ->assertNoContent();
    }

    public function test_expired_token_is_a_401(): void
    {
        $token = $this->issueToken($this->memberOf(Organization::factory()->create()));

        $this->travel(30)->days();
        $this->travel(-1)->minutes();
        $this->asNativeClient($token)->getJson('/api/v1/auth/me')->assertOk();

        $this->travel(2)->minutes();
        $this->asNativeClient($token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_deleting_the_current_token_revokes_only_that_token(): void
    {
        $user = $this->memberOf(Organization::factory()->create());
        $phone = $this->issueToken($user, 'Pixel 8 · a1b2');
        $tablet = $this->issueToken($user, 'Galaxy Tab · c3d4');

        $this->asNativeClient($phone)->deleteJson('/api/v1/auth/tokens/current')->assertNoContent();

        $this->assertSame(['Galaxy Tab · c3d4'], $user->tokens()->pluck('name')->all());
        $this->asNativeClient($phone)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->asNativeClient($phone)->deleteJson('/api/v1/auth/tokens/current')->assertUnauthorized();
        $this->asNativeClient($tablet)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_deleting_the_current_token_of_a_session_is_a_400(): void
    {
        $user = $this->memberOf(Organization::factory()->create());
        $user->createToken(self::DEVICE, ['*'], now()->addDay());

        $this->actingAs($user)
            ->deleteJson('/api/v1/auth/tokens/current')
            ->assertStatus(400)
            ->assertExactJson(['message' => 'This request is not authenticated with an access token.']);

        $this->assertSame(1, $user->tokens()->count());
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_logout_with_a_token_revokes_it_instead_of_touching_a_session(): void
    {
        $token = $this->issueToken($this->memberOf(Organization::factory()->create()));

        $this->asNativeClient($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertExactJson(['message' => 'Logged out.']);

        $this->assertSame(0, PersonalAccessToken::query()->count());
        $this->asNativeClient($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_token_does_not_authenticate_the_ingestion_api(): void
    {
        $token = $this->issueToken($this->memberOf(Organization::factory()->create()));

        $this->asNativeClient($token)
            ->getJson('/api/v1/ingest/transactions/order-1')
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer')
            ->assertExactJson(['message' => 'Invalid API key.', 'code' => 'invalid_api_key']);
    }

    /**
     * The full flow a native client goes through, as in the curl walkthrough of docs/api.md.
     */
    public function test_issue_read_revoke_flow(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->memberOf($organization);

        $token = $this->asNativeClient()
            ->postJson('/api/v1/auth/tokens', $this->credentials($user))
            ->assertCreated()
            ->json('token');

        $this->asNativeClient($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->asNativeClient($token)
            ->withHeader('X-Organization-Id', $organization->id)
            ->getJson('/api/v1/dashboard')
            ->assertOk();
        $this->asNativeClient($token)->deleteJson('/api/v1/auth/tokens/current')->assertNoContent();
        $this->asNativeClient($token)
            ->withHeader('X-Organization-Id', $organization->id)
            ->getJson('/api/v1/dashboard')
            ->assertUnauthorized();
    }

    /**
     * A native client sends no SPA Origin/Referer (so no session or CSRF). Guards are
     * forgotten because Sanctum's RequestGuard caches the user for the app lifetime in tests.
     */
    private function asNativeClient(?string $token = null): static
    {
        Auth::forgetGuards();
        $this->flushHeaders();

        return $token === null ? $this : $this->withToken($token);
    }

    /**
     * @return array{email: string, password: string, device_name: string}
     */
    private function credentials(User $user, string $deviceName = self::DEVICE): array
    {
        return ['email' => $user->email, 'password' => self::PASSWORD, 'device_name' => $deviceName];
    }

    private function issueToken(User $user, string $deviceName = self::DEVICE): string
    {
        return $this->asNativeClient()
            ->postJson('/api/v1/auth/tokens', $this->credentials($user, $deviceName))
            ->assertCreated()
            ->json('token');
    }
}
