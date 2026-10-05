<?php

namespace Tests\Feature\Api;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Concerns\InteractsWithIngestApi;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

class RequestIdTest extends TestCase
{
    use InteractsWithIngestApi, InteractsWithOrganizationApi, RefreshDatabase;

    public function test_the_spa_origin_can_read_the_request_id_and_retry_after(): void
    {
        $exposed = $this->getJson('/api/v1/health')->headers->get('Access-Control-Expose-Headers');

        $this->assertNotNull($exposed);
        $headers = array_map(fn (string $header) => strtolower(trim($header)), explode(',', $exposed));
        $this->assertContains('x-request-id', $headers);
        $this->assertContains('retry-after', $headers);
    }

    public function test_every_api_response_carries_a_generated_request_id(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->memberOf($organization);
        [, $plainTextKey] = $this->issueApiKey($organization);

        $responses = [
            'health' => $this->getJson('/api/v1/health'),
            'unknown route' => $this->getJson('/api/v1/does-not-exist'),
            'unauthenticated' => $this->getJson('/api/v1/products'),
            'validation error' => $this->postJson('/api/v1/auth/login', []),
            'internal' => $this->actingInOrganization($owner, $organization)->getJson('/api/v1/products'),
            'ingest' => $this->asIntegration($plainTextKey)->getJson('/api/v1/ingest/transactions/missing'),
            'ingest without key' => $this->asIntegration(null)->getJson('/api/v1/ingest/transactions/missing'),
        ];

        foreach ($responses as $case => $response) {
            $this->assertTrue(Str::isUuid($response->headers->get('X-Request-Id')), "{$case} has a request id");
        }

        $ids = array_map(fn ($response) => $response->headers->get('X-Request-Id'), $responses);
        $this->assertCount(count($responses), array_unique($ids));
    }

    public function test_server_errors_carry_the_request_id(): void
    {
        config(['app.debug' => false]);
        $this->app['router']->get('/api/v1/__boom', fn () => throw new \RuntimeException('secret detail'));

        $response = $this->getJson('/api/v1/__boom')->assertStatus(500);

        $this->assertTrue(Str::isUuid($response->headers->get('X-Request-Id')));
    }

    public function test_a_well_formed_incoming_request_id_is_kept(): void
    {
        $this->withHeader('X-Request-Id', 'shop-retry_42.attempt-3')
            ->getJson('/api/v1/health')
            ->assertHeader('X-Request-Id', 'shop-retry_42.attempt-3');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedIncomingIds(): array
    {
        return [
            'too short' => ['abc1234'],
            'too long' => [str_repeat('a', 65)],
            'spaces' => ['request id 123'],
            'log injection' => ["abcdefgh\nlevel=error"],
            'slash' => ['abcd/efgh'],
        ];
    }

    #[DataProvider('malformedIncomingIds')]
    public function test_a_malformed_incoming_request_id_is_replaced(string $incoming): void
    {
        $response = $this->withHeader('X-Request-Id', $incoming)->getJson('/api/v1/health')->assertOk();

        $this->assertTrue(Str::isUuid($response->headers->get('X-Request-Id')));
    }

    public function test_the_request_id_is_shared_with_every_log_line(): void
    {
        $this->withHeader('X-Request-Id', 'trace-me-123')->getJson('/api/v1/health')->assertOk();

        $this->assertSame('trace-me-123', Log::sharedContext()['request_id'] ?? null);
    }

    public function test_non_api_routes_are_left_alone(): void
    {
        $this->get('/')->assertHeaderMissing('X-Request-Id');
    }
}
