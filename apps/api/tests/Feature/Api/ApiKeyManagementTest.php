<?php

namespace Tests\Feature\Api;

use App\Console\Commands\DemoCommand;
use App\Models\ApiKey;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Concerns\InteractsWithIngestApi;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

class ApiKeyManagementTest extends TestCase
{
    use InteractsWithIngestApi, InteractsWithOrganizationApi, RefreshDatabase;

    public function test_owner_creates_a_key_and_sees_the_secret_only_in_that_response(): void
    {
        $this->freezeSecond();
        $organization = Organization::factory()->create();
        $owner = $this->memberOf($organization);

        $response = $this->actingInOrganization($owner, $organization)
            ->postJson('/api/v1/api-keys', ['name' => 'Online store'])
            ->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.name', 'Online store')
            ->assertJsonPath('data.status', ApiKey::STATUS_ACTIVE)
            ->assertJsonPath('data.created_by', ['id' => $owner->id, 'name' => $owner->name])
            ->assertJsonPath('data.expires_at', null)
            ->assertJsonPath('data.revoked_at', null)
            ->assertJsonPath('data.last_used_at', null)
            ->assertJsonMissingPath('data.secret_hash');

        $plainTextKey = $response->json('data.plain_text_key');
        $this->assertMatchesRegularExpression('/^pb_[A-Za-z0-9]{12}_[A-Za-z0-9]{40}$/', $plainTextKey);
        [, $prefix, $secret] = explode('_', $plainTextKey);

        $apiKey = ApiKey::query()->sole();
        $this->assertSame($response->json('data.id'), $apiKey->id);
        $this->assertSame($prefix, $response->json('data.prefix'));
        $this->assertSame($prefix, $apiKey->prefix);
        $this->assertSame($organization->id, $apiKey->organization_id);
        $this->assertSame($owner->id, $apiKey->created_by_user_id);
        $this->assertSame(hash('sha256', $secret), $apiKey->secret_hash);

        $row = json_encode(DB::table('api_keys')->first());
        $this->assertStringNotContainsString($secret, $row);
        $this->assertStringNotContainsString($plainTextKey, $row);
    }

    public function test_listing_never_contains_the_secret_or_its_hash(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->memberOf($organization);

        $created = $this->actingInOrganization($owner, $organization)
            ->postJson('/api/v1/api-keys', ['name' => 'Online store'])
            ->assertCreated();
        [, , $secret] = explode('_', $created->json('data.plain_text_key'));

        $listing = $this->actingInOrganization($owner, $organization)
            ->getJson('/api/v1/api-keys')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.plain_text_key')
            ->assertJsonMissingPath('data.0.secret_hash');

        $this->assertSame(
            ['id', 'name', 'prefix', 'status', 'created_by', 'last_used_at', 'expires_at', 'revoked_at', 'created_at'],
            array_keys($listing->json('data.0')),
        );
        $this->assertStringNotContainsString($secret, $listing->getContent());
        $this->assertStringNotContainsString(ApiKey::query()->sole()->secret_hash, $listing->getContent());
    }

    public function test_member_lists_keys_of_the_current_organization_newest_first(): void
    {
        $organization = Organization::factory()->create();
        $member = $this->memberOf($organization, Organization::ROLE_MEMBER);
        $old = ApiKey::factory()->for($organization)->create(['created_at' => now()->subDays(2)]);
        $revoked = ApiKey::factory()->for($organization)->revoked()->create(['created_at' => now()->subDay()]);
        $new = ApiKey::factory()->for($organization)->expired()->create(['created_at' => now()]);
        ApiKey::factory()->for(Organization::factory())->create();

        $this->actingInOrganization($member, $organization)
            ->getJson('/api/v1/api-keys')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$new->id, $revoked->id, $old->id])
            ->assertJsonPath('data.*.status', [ApiKey::STATUS_EXPIRED, ApiKey::STATUS_REVOKED, ApiKey::STATUS_ACTIVE])
            ->assertJsonPath('data.0.created_by', null);
    }

    public function test_member_cannot_create_or_revoke_keys(): void
    {
        $organization = Organization::factory()->create();
        $member = $this->memberOf($organization, Organization::ROLE_MEMBER);
        $apiKey = ApiKey::factory()->for($organization)->create();

        $this->actingInOrganization($member, $organization)
            ->postJson('/api/v1/api-keys', ['name' => 'Online store'])
            ->assertForbidden();

        $this->actingInOrganization($member, $organization)
            ->deleteJson("/api/v1/api-keys/{$apiKey->id}")
            ->assertForbidden();

        $this->assertSame(1, ApiKey::query()->count());
        $this->assertNull($apiKey->refresh()->revoked_at);
    }

    /**
     * @return array<string, array{0: int|null, 1: int|null}>
     */
    public static function expirationOptions(): array
    {
        return [
            'no expiration (null)' => [null, null],
            '30 days' => [30, 30],
            '90 days' => [90, 90],
            '365 days' => [365, 365],
        ];
    }

    #[DataProvider('expirationOptions')]
    public function test_expiration_is_one_of_the_offered_options(?int $requested, ?int $expectedDays): void
    {
        $this->freezeSecond();
        $organization = Organization::factory()->create();
        $owner = $this->memberOf($organization);

        $this->actingInOrganization($owner, $organization)
            ->postJson('/api/v1/api-keys', ['name' => 'Online store', 'expires_in_days' => $requested])
            ->assertCreated();

        $this->assertEquals(
            $expectedDays === null ? null : now()->addDays($expectedDays),
            ApiKey::query()->sole()->expires_at,
        );
    }

    public function test_omitting_the_expiration_creates_a_key_that_does_not_expire(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->memberOf($organization);

        $this->actingInOrganization($owner, $organization)
            ->postJson('/api/v1/api-keys', ['name' => 'Online store'])
            ->assertCreated();

        $this->assertNull(ApiKey::query()->sole()->expires_at);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'missing name' => [[], 'name'],
            'name too long' => [['name' => str_repeat('a', 101)], 'name'],
            'unsupported expiration' => [['name' => 'Shop', 'expires_in_days' => 7], 'expires_in_days'],
            'expiration as a date' => [['name' => 'Shop', 'expires_in_days' => '2030-01-01'], 'expires_in_days'],
            'organization in the payload' => [['name' => 'Shop', 'organization_id' => '00000000-0000-0000-0000-000000000000'], 'organization_id'],
            'chosen prefix' => [['name' => 'Shop', 'prefix' => 'Ab12Cd34Ef56'], 'prefix'],
            'chosen hash' => [['name' => 'Shop', 'secret_hash' => str_repeat('a', 64)], 'secret_hash'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_payloads_are_rejected(array $payload, string $field): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->memberOf($organization);

        $this->actingInOrganization($owner, $organization)
            ->postJson('/api/v1/api-keys', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertSame(0, ApiKey::query()->count());
    }

    /**
     * @return array<string, array{0: int|null}>
     */
    public static function demoRequestedExpirations(): array
    {
        return [
            'no expiration' => [null],
            '30 days' => [30],
            '365 days' => [365],
        ];
    }

    #[DataProvider('demoRequestedExpirations')]
    public function test_keys_in_the_demo_organization_always_expire_in_24_hours(?int $requested): void
    {
        $this->freezeSecond();
        $demo = Organization::factory()->create(['slug' => DemoCommand::ORGANIZATION_SLUG]);
        $owner = $this->memberOf($demo);

        $response = $this->actingInOrganization($owner, $demo)
            ->postJson('/api/v1/api-keys', ['name' => 'Visitor', 'expires_in_days' => $requested])
            ->assertCreated();

        $this->assertEquals(now()->addHours(24), ApiKey::query()->sole()->expires_at);
        $this->assertSame(now()->addHours(24)->toJSON(), $response->json('data.expires_at'));
    }

    public function test_owner_revokes_a_key_which_stops_authenticating(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->memberOf($organization);
        [$apiKey, $plainTextKey] = $this->issueApiKey($organization);
        $this->createIngestedTransaction($organization, 'order-1');

        $this->asIntegration($plainTextKey)->getJson('/api/v1/ingest/transactions/order-1')->assertOk();

        $this->withHeaders(['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000'])
            ->withoutHeaders(['Authorization'])
            ->actingInOrganization($owner, $organization)
            ->deleteJson("/api/v1/api-keys/{$apiKey->id}")
            ->assertNoContent();

        $this->assertNotNull($apiKey->refresh()->revoked_at);
        $this->assertSame(ApiKey::STATUS_REVOKED, $apiKey->status());
        $this->assertModelExists($apiKey);

        $this->asIntegration($plainTextKey)
            ->getJson('/api/v1/ingest/transactions/order-1')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'invalid_api_key');
    }

    public function test_revoking_twice_keeps_the_original_revocation_time(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->memberOf($organization);
        $apiKey = ApiKey::factory()->for($organization)->revoked()->create();
        $revokedAt = $apiKey->revoked_at;

        $this->actingInOrganization($owner, $organization)
            ->deleteJson("/api/v1/api-keys/{$apiKey->id}")
            ->assertNoContent();

        $this->assertEquals($revokedAt, $apiKey->refresh()->revoked_at);
    }

    public function test_key_of_another_organization_is_not_found(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->memberOf($organization);
        $foreign = ApiKey::factory()->for(Organization::factory())->create();

        $this->actingInOrganization($owner, $organization)
            ->deleteJson("/api/v1/api-keys/{$foreign->id}")
            ->assertNotFound()
            ->assertExactJson(['message' => 'Resource not found.']);

        $this->assertNull($foreign->refresh()->revoked_at);
    }

    public function test_an_organization_has_at_most_10_active_keys(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->memberOf($organization);
        $active = ApiKey::factory()->for($organization)->count(ApiKey::MAX_ACTIVE_PER_ORGANIZATION)->create();
        ApiKey::factory()->for($organization)->revoked()->create();
        ApiKey::factory()->for($organization)->expired()->create();
        ApiKey::factory()->for(Organization::factory())->create();

        $this->actingInOrganization($owner, $organization)
            ->postJson('/api/v1/api-keys', ['name' => 'Eleventh'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('api_keys');

        $this->assertSame(12, $organization->apiKeys()->count());

        $active->first()->revoke();

        $this->actingInOrganization($owner, $organization)
            ->postJson('/api/v1/api-keys', ['name' => 'Eleventh'])
            ->assertCreated();
    }

    public function test_requires_an_authenticated_member_of_the_organization(): void
    {
        $organization = Organization::factory()->create();
        $outsider = $this->memberOf(Organization::factory()->create());

        $this->withHeader('X-Organization-Id', $organization->id)
            ->getJson('/api/v1/api-keys')
            ->assertUnauthorized();

        $this->actingInOrganization($outsider, $organization)
            ->postJson('/api/v1/api-keys', ['name' => 'Shop'])
            ->assertForbidden();

        $this->assertSame(0, ApiKey::query()->count());
    }
}
