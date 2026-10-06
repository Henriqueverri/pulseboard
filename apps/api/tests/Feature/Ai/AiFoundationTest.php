<?php

namespace Tests\Feature\Ai;

use App\Exceptions\AiException;
use App\Models\Organization;
use App\Services\Ai\Fake\ScriptedLlmClient;
use App\Services\Ai\LlmClient;
use App\Services\Ai\OpenAi\OpenAiResponsesClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use LogicException;
use RuntimeException;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

class AiFoundationTest extends TestCase
{
    use InteractsWithOrganizationApi, RefreshDatabase;

    public function test_ai_is_disabled_and_scripted_by_default_in_tests(): void
    {
        $this->assertFalse(config('ai.enabled'));
        $this->assertInstanceOf(ScriptedLlmClient::class, app(LlmClient::class));
        $this->assertSame('', (string) config('services.openai.key'));
    }

    public function test_no_test_can_reach_a_real_host(): void
    {
        $this->expectException(RuntimeException::class);

        Http::post('https://api.openai.com/v1/responses', []);
    }

    public function test_the_openai_provider_is_bound_from_config(): void
    {
        config(['ai.provider' => 'openai', 'services.openai.key' => 'sk-test']);

        $this->assertInstanceOf(OpenAiResponsesClient::class, app(LlmClient::class));
        $this->assertSame('openai', app(LlmClient::class)->provider());
    }

    public function test_the_scripted_provider_is_refused_in_production(): void
    {
        $this->app['env'] = 'production';
        config(['ai.provider' => 'scripted']);

        try {
            $this->expectException(LogicException::class);
            app(LlmClient::class);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_ai_errors_render_with_a_stable_code_and_retry_after(): void
    {
        Route::middleware('api')->get('/api/v1/__ai-error/{case}', fn (string $case) => throw match ($case) {
            'quota' => AiException::quotaExceeded(120),
            'timeout' => AiException::timeout(),
            'invalid' => AiException::invalidOutput(),
            'provider' => AiException::providerUnavailable(),
            'not-enabled' => AiException::notEnabled(),
        });

        $this->getJson('/api/v1/__ai-error/quota')
            ->assertStatus(429)
            ->assertHeader('Retry-After', '120')
            ->assertExactJson(['message' => 'The insights quota has been reached.', 'code' => 'ai_quota_exceeded']);

        $this->getJson('/api/v1/__ai-error/timeout')->assertStatus(504)->assertJsonPath('code', 'ai_timeout')->assertHeaderMissing('Retry-After');
        $this->getJson('/api/v1/__ai-error/invalid')->assertStatus(502)->assertJsonPath('code', 'ai_invalid_output');
        $this->getJson('/api/v1/__ai-error/provider')->assertStatus(503)->assertJsonPath('code', 'ai_provider_unavailable');
        $this->getJson('/api/v1/__ai-error/not-enabled')->assertStatus(403)->assertJsonPath('code', 'ai_not_enabled');
    }

    public function test_the_insights_rate_limit_is_per_user_and_organization(): void
    {
        config(['ai.limits.requests_per_minute' => 2]);
        $this->registerThrottledRoute();
        $organization = Organization::factory()->create();
        $first = $this->memberOf($organization);
        $second = $this->memberOf($organization);

        $this->actingInOrganization($first, $organization)->postJson('/api/v1/__insights-throttled')->assertOk();
        $this->actingInOrganization($first, $organization)->postJson('/api/v1/__insights-throttled')->assertOk();
        $this->actingInOrganization($first, $organization)->postJson('/api/v1/__insights-throttled')
            ->assertStatus(429)
            ->assertJsonPath('code', 'rate_limited')
            ->assertHeader('Retry-After');

        $this->actingInOrganization($second, $organization)->postJson('/api/v1/__insights-throttled')->assertOk();
    }

    public function test_the_demo_organization_is_also_limited_per_ip(): void
    {
        config(['ai.limits.requests_per_minute' => 10, 'ai.limits.demo_requests_per_minute_per_ip' => 1]);
        $this->registerThrottledRoute();
        $demo = Organization::factory()->create(['slug' => 'pulseboard-demo']);
        $this->assertTrue($demo->isDemo());
        [$visitorA, $visitorB] = [$this->memberOf($demo), $this->memberOf($demo)];

        $this->actingInOrganization($visitorA, $demo)->postJson('/api/v1/__insights-throttled')->assertOk();
        $this->actingInOrganization($visitorB, $demo)->postJson('/api/v1/__insights-throttled')->assertStatus(429);

        $this->actingInOrganization($visitorB, $demo)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson('/api/v1/__insights-throttled')
            ->assertOk();
    }

    private function registerThrottledRoute(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'organization', 'throttle:insights'])
            ->post('/api/v1/__insights-throttled', fn () => response()->json(['ok' => true]));
    }
}
