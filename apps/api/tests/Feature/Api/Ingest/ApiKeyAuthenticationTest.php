<?php

namespace Tests\Feature\Api\Ingest;

use App\Models\ApiKey;
use App\Models\Organization;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Concerns\InteractsWithIngestApi;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

class ApiKeyAuthenticationTest extends TestCase
{
    use InteractsWithIngestApi, InteractsWithOrganizationApi, RefreshDatabase;

    private const INVALID_KEY_RESPONSE = ['message' => 'Invalid API key.', 'code' => 'invalid_api_key'];

    public function test_a_valid_key_reads_a_transaction_of_its_organization_by_external_id(): void
    {
        $organization = Organization::factory()->create(['currency' => 'BRL']);
        [$apiKey, $plainTextKey] = $this->issueApiKey($organization);
        $transaction = $this->createIngestedTransaction($organization, 'shop:order-1', $apiKey);

        $response = $this->asIntegration($plainTextKey)
            ->getJson('/api/v1/ingest/transactions/shop:order-1')
            ->assertOk()
            ->assertJsonPath('data.id', $transaction->id)
            ->assertJsonPath('data.external_id', 'shop:order-1')
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.currency', 'BRL')
            ->assertJsonPath('data.total_amount', '99.80')
            ->assertJsonPath('data.occurred_at', $transaction->occurred_at->toJSON())
            ->assertJsonPath('data.customer', ['id' => $transaction->customer_id, 'external_id' => 'cus-shop:order-1'])
            ->assertJsonPath('data.items.0.sku', $transaction->items->first()->product->sku)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.unit_price', '49.90')
            ->assertJsonPath('data.items.0.line_total', '99.80')
            ->assertJsonPath('data.status_history.0.from_status', null)
            ->assertJsonPath('data.status_history.0.to_status', 'paid')
            ->assertJsonPath('data.status_history.0.source', 'ingest');

        $this->assertStringNotContainsString($transaction->customer->email, $response->getContent());
        $this->assertStringNotContainsString($transaction->customer->name, $response->getContent());
    }

    public function test_status_history_follows_the_chain_from_creation(): void
    {
        $organization = Organization::factory()->create();
        [, $plainTextKey] = $this->issueApiKey($organization);
        $transaction = $this->createIngestedTransaction($organization, 'order-1');
        $transaction->statusChanges()->delete();
        // Same timestamps on purpose: only the chain can order them.
        foreach ([['paid', 'refunded'], [null, 'paid']] as [$from, $to]) {
            $transaction->statusChanges()->create([
                'organization_id' => $organization->id,
                'from_status' => $from,
                'to_status' => $to,
                'occurred_at' => $transaction->occurred_at,
                'source' => 'ingest',
            ]);
        }
        $transaction->forceFill(['status' => 'refunded'])->save();

        $this->asIntegration($plainTextKey)
            ->getJson('/api/v1/ingest/transactions/order-1')
            ->assertOk()
            ->assertJsonPath('data.status', 'refunded')
            ->assertJsonPath('data.status_history.*.to_status', ['paid', 'refunded']);
    }

    public function test_unknown_external_id_is_a_404(): void
    {
        $organization = Organization::factory()->create();
        [, $plainTextKey] = $this->issueApiKey($organization);

        $this->asIntegration($plainTextKey)
            ->getJson('/api/v1/ingest/transactions/does-not-exist')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Transaction not found.', 'code' => 'not_found']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedCredentials(): array
    {
        return [
            'missing' => ['missing'],
            'empty bearer' => ['empty'],
            'basic scheme' => ['basic'],
            'malformed' => ['malformed'],
            'unknown prefix' => ['unknown'],
            'wrong secret' => ['wrong_secret'],
            'revoked' => ['revoked'],
            'expired' => ['expired'],
        ];
    }

    #[DataProvider('rejectedCredentials')]
    public function test_every_rejected_credential_is_the_same_generic_401(string $case): void
    {
        $organization = Organization::factory()->create();
        $this->createIngestedTransaction($organization, 'order-1');
        [$valid, $validKey] = $this->issueApiKey($organization);
        [$prefix] = explode('_', substr($validKey, 3));

        $this->asIntegration(null);
        match ($case) {
            'missing' => null,
            'empty' => $this->withHeader('Authorization', 'Bearer '),
            'basic' => $this->withHeader('Authorization', 'Basic '.base64_encode('user:pass')),
            'malformed' => $this->withToken('pb_not-a-key'),
            'unknown' => $this->withToken('pb_ZZZZZZZZZZZZ_'.str_repeat('a', 40)),
            'wrong_secret' => $this->withToken("pb_{$prefix}_".str_repeat('a', 40)),
            'revoked' => $this->withToken($this->issueApiKey($organization, ['revoked_at' => now()->subSecond()])[1]),
            'expired' => $this->withToken($this->issueApiKey($organization, ['expires_at' => now()->subSecond()])[1]),
        };

        $this->getJson('/api/v1/ingest/transactions/order-1')
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer')
            ->assertExactJson(self::INVALID_KEY_RESPONSE);

        $this->assertNull($valid->refresh()->last_used_at);
    }

    public function test_a_key_that_has_not_expired_yet_authenticates(): void
    {
        $organization = Organization::factory()->create();
        [, $plainTextKey] = $this->issueApiKey($organization, ['expires_at' => now()->addMinute()]);
        $this->createIngestedTransaction($organization, 'order-1');

        $this->asIntegration($plainTextKey)->getJson('/api/v1/ingest/transactions/order-1')->assertOk();

        $this->travel(2)->minutes();

        $this->getJson('/api/v1/ingest/transactions/order-1')->assertUnauthorized();
    }

    public function test_failures_are_logged_with_the_reason_but_never_the_secret(): void
    {
        $organization = Organization::factory()->create();
        [$apiKey, $plainTextKey] = $this->issueApiKey($organization, ['revoked_at' => now()]);
        [, , $secret] = explode('_', $plainTextKey);
        Log::spy();

        $this->asIntegration($plainTextKey)->getJson('/api/v1/ingest/transactions/order-1')->assertUnauthorized();

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use ($apiKey, $secret, $plainTextKey): bool {
            $logged = $message.json_encode($context);

            return $context === ['event' => 'ingest.auth_failed', 'reason' => 'revoked', 'api_key_prefix' => $apiKey->prefix]
                && ! str_contains($logged, $secret)
                && ! str_contains($logged, $plainTextKey)
                && ! str_contains($logged, $apiKey->secret_hash);
        });
    }

    public function test_a_key_never_reads_another_organizations_transaction(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        [, $plainTextKey] = $this->issueApiKey($organization);
        $this->createIngestedTransaction($other, 'their-order');
        $ours = $this->createIngestedTransaction($organization, 'shared-id');
        $this->createIngestedTransaction($other, 'shared-id');

        $this->asIntegration($plainTextKey)
            ->getJson('/api/v1/ingest/transactions/their-order')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Transaction not found.', 'code' => 'not_found']);

        $this->getJson('/api/v1/ingest/transactions/shared-id')
            ->assertOk()
            ->assertJsonPath('data.id', $ours->id);
    }

    public function test_the_organization_header_is_ignored(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        [, $plainTextKey] = $this->issueApiKey($organization);
        $ours = $this->createIngestedTransaction($organization, 'our-order');
        $this->createIngestedTransaction($other, 'their-order');

        $this->asIntegration($plainTextKey)->withHeader('X-Organization-Id', $other->id);

        $this->getJson('/api/v1/ingest/transactions/our-order')
            ->assertOk()
            ->assertJsonPath('data.id', $ours->id);

        $this->getJson('/api/v1/ingest/transactions/their-order')->assertNotFound();
    }

    public function test_a_valid_key_is_rejected_on_internal_routes(): void
    {
        $organization = Organization::factory()->create();
        [, $plainTextKey] = $this->issueApiKey($organization);

        foreach (['/api/v1/products', '/api/v1/transactions', '/api/v1/api-keys', '/api/v1/dashboard'] as $uri) {
            $this->asIntegration($plainTextKey)
                ->withHeader('X-Organization-Id', $organization->id)
                ->getJson($uri)
                ->assertUnauthorized()
                ->assertExactJson(['message' => 'Unauthenticated.']);
        }

        $this->postJson('/api/v1/api-keys', ['name' => 'Escalation'])->assertUnauthorized();
        $this->assertSame(1, ApiKey::query()->count());
    }

    public function test_a_session_is_rejected_on_ingest_routes(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->memberOf($organization);
        $this->createIngestedTransaction($organization, 'order-1');

        $this->actingInOrganization($owner, $organization)
            ->getJson('/api/v1/ingest/transactions/order-1')
            ->assertUnauthorized()
            ->assertExactJson(self::INVALID_KEY_RESPONSE);

        $this->actingAs($owner)
            ->asIntegration(null)
            ->getJson('/api/v1/ingest/transactions/order-1')
            ->assertUnauthorized()
            ->assertExactJson(self::INVALID_KEY_RESPONSE);
    }

    public function test_last_used_at_is_written_at_most_once_per_minute(): void
    {
        $this->freezeSecond();
        $organization = Organization::factory()->create();
        [$apiKey, $plainTextKey] = $this->issueApiKey($organization);
        $updatedAt = $apiKey->updated_at;

        $this->asIntegration($plainTextKey)->getJson('/api/v1/ingest/transactions/x')->assertNotFound();
        $this->assertEquals(now(), $apiKey->refresh()->last_used_at);

        $this->travel(30)->seconds();
        $this->getJson('/api/v1/ingest/transactions/x')->assertNotFound();
        $this->assertEquals(now()->subSeconds(30), $apiKey->refresh()->last_used_at);

        $this->travel(31)->seconds();
        $this->getJson('/api/v1/ingest/transactions/x')->assertNotFound();
        $this->assertEquals(now(), $apiKey->refresh()->last_used_at);

        $this->assertEquals($updatedAt, $apiKey->updated_at);
    }

    public function test_rate_limit_is_per_key(): void
    {
        $organization = Organization::factory()->create();
        [, $first] = $this->issueApiKey($organization);
        [, $second] = $this->issueApiKey($organization);

        $this->asIntegration($first);
        for ($i = 0; $i < AppServiceProvider::INGEST_REQUESTS_PER_MINUTE; $i++) {
            $this->getJson('/api/v1/ingest/transactions/x')->assertNotFound();
        }

        $this->getJson('/api/v1/ingest/transactions/x')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertHeader('X-Request-Id')
            ->assertExactJson(['message' => 'Too many requests.', 'code' => 'rate_limited']);

        $this->asIntegration($second)->getJson('/api/v1/ingest/transactions/x')->assertNotFound();

        $this->travel(61)->seconds();
        $this->asIntegration($first)->getJson('/api/v1/ingest/transactions/x')->assertNotFound();
    }

    public function test_rejected_credentials_do_not_consume_a_keys_budget(): void
    {
        $organization = Organization::factory()->create();
        [, $plainTextKey] = $this->issueApiKey($organization);
        [$prefix] = explode('_', substr($plainTextKey, 3));

        $this->asIntegration("pb_{$prefix}_".str_repeat('a', 40));
        for ($i = 0; $i < AppServiceProvider::INGEST_REQUESTS_PER_MINUTE + 5; $i++) {
            $this->getJson('/api/v1/ingest/transactions/x')->assertUnauthorized();
        }

        $this->asIntegration($plainTextKey)->getJson('/api/v1/ingest/transactions/x')->assertNotFound();
    }
}
