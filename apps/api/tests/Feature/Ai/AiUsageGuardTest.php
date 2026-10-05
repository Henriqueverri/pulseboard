<?php

namespace Tests\Feature\Ai;

use App\Data\Ai\AiContext;
use App\Exceptions\AiException;
use App\Models\AiRun;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiUsageGuard;
use App\Support\Analytics\ReportingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Api\Concerns\InteractsWithOrganizationApi;
use Tests\TestCase;

class AiUsageGuardTest extends TestCase
{
    use InteractsWithOrganizationApi, RefreshDatabase;

    private Organization $organization;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.enabled' => true,
            'ai.limits.daily_per_organization' => 3,
            'ai.limits.daily_per_user' => 2,
        ]);

        $this->organization = Organization::factory()->create(['timezone' => 'America/Sao_Paulo']);
        $this->organization->enableInsights(null);
        $this->user = $this->memberOf($this->organization);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_kill_switch_comes_first(): void
    {
        config(['ai.enabled' => false]);

        $this->assertGuardFails(AiException::DISABLED, 503);
    }

    public function test_an_organization_without_opt_in_is_rejected(): void
    {
        $this->organization->disableInsights();

        $this->assertGuardFails(AiException::NOT_ENABLED, 403);
    }

    public function test_runs_below_the_quotas_pass(): void
    {
        $this->recordRun($this->user, AiRun::STATUS_SUCCEEDED);

        app(AiUsageGuard::class)->authorize($this->context(), AiRun::FEATURE_PERIOD_SUMMARY);

        $this->assertSame(1, AiRun::query()->count());
    }

    public function test_the_daily_user_quota_counts_runs_that_reached_the_provider(): void
    {
        $this->recordRun($this->user, AiRun::STATUS_SUCCEEDED);
        $this->recordRun($this->user, AiRun::STATUS_INVALID_OUTPUT);

        $this->assertGuardFails(AiException::QUOTA_EXCEEDED, 429);
        $this->assertDatabaseHas('ai_runs', [
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'status' => AiRun::STATUS_QUOTA_EXCEEDED,
            'error_code' => AiException::QUOTA_EXCEEDED,
            'input_tokens' => 0,
            'cost_micros' => 0,
        ]);
    }

    public function test_the_daily_organization_quota_is_shared_by_its_members(): void
    {
        $other = $this->memberOf($this->organization, Organization::ROLE_MEMBER);
        $this->recordRun($other, AiRun::STATUS_SUCCEEDED);
        $this->recordRun($other, AiRun::STATUS_TIMEOUT);
        $this->recordRun($this->user, AiRun::STATUS_PROVIDER_ERROR);

        $this->assertGuardFails(AiException::QUOTA_EXCEEDED, 429);
    }

    public function test_cache_hits_and_rejected_runs_are_free(): void
    {
        foreach ([AiRun::STATUS_CACHE_HIT, AiRun::STATUS_CACHE_HIT, AiRun::STATUS_QUOTA_EXCEEDED, AiRun::STATUS_QUOTA_EXCEEDED] as $status) {
            $this->recordRun($this->user, $status);
        }

        app(AiUsageGuard::class)->authorize($this->context(), AiRun::FEATURE_PERIOD_SUMMARY);

        $this->addToAssertionCount(1);
    }

    public function test_quotas_reset_at_midnight_in_the_organization_timezone(): void
    {
        // 02:30 UTC is still 23:30 of the previous day in São Paulo (UTC-3).
        Carbon::setTestNow('2026-10-05 02:30:00');
        $this->recordRun($this->user, AiRun::STATUS_SUCCEEDED);
        $this->recordRun($this->user, AiRun::STATUS_SUCCEEDED);

        $this->assertGuardFails(AiException::QUOTA_EXCEEDED, 429, expectedRetryAfter: 30 * 60);

        Carbon::setTestNow('2026-10-05 03:00:00');

        app(AiUsageGuard::class)->authorize($this->context(), AiRun::FEATURE_PERIOD_SUMMARY);
        $this->addToAssertionCount(1);
    }

    public function test_another_organization_runs_never_count(): void
    {
        $other = Organization::factory()->create();
        $otherUser = $this->memberOf($other);

        foreach (range(1, 5) as $ignored) {
            AiRun::query()->create($this->attributes($other, $otherUser, AiRun::STATUS_SUCCEEDED));
        }

        app(AiUsageGuard::class)->authorize($this->context(), AiRun::FEATURE_PERIOD_SUMMARY);
        $this->addToAssertionCount(1);
    }

    private function context(): AiContext
    {
        return AiContext::for($this->organization->refresh(), $this->user, ReportingPeriod::lastDays(30, $this->organization->timezone));
    }

    private function recordRun(User $user, string $status): void
    {
        AiRun::query()->create($this->attributes($this->organization, $user, $status));
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(Organization $organization, User $user, string $status): array
    {
        return [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'feature' => AiRun::FEATURE_PERIOD_SUMMARY,
            'provider' => 'scripted',
            'model' => 'scripted',
            'status' => $status,
            'created_at' => now(),
        ];
    }

    private function assertGuardFails(string $code, int $status, ?int $expectedRetryAfter = null): void
    {
        try {
            app(AiUsageGuard::class)->authorize($this->context(), AiRun::FEATURE_PERIOD_SUMMARY);
            $this->fail('Expected an AiException.');
        } catch (AiException $exception) {
            $this->assertSame($code, $exception->errorCode);
            $this->assertSame($status, $exception->httpStatus);

            if ($expectedRetryAfter !== null) {
                $this->assertSame($expectedRetryAfter, $exception->retryAfter);
            }
        }
    }
}
