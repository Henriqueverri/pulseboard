<?php

namespace Tests\Feature\Ai;

use App\Console\Commands\DemoCommand;
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

    public function test_the_monthly_budget_is_global_and_disables_new_generations(): void
    {
        config(['ai.monthly_budget_usd' => 0.05, 'ai.limits.daily_per_organization' => 100, 'ai.limits.daily_per_user' => 100]);
        $other = Organization::factory()->create();
        $otherUser = $this->memberOf($other);

        AiRun::query()->create([...$this->attributes($other, $otherUser, AiRun::STATUS_SUCCEEDED), 'cost_micros' => 30_000]);
        $this->recordRun($this->user, AiRun::STATUS_INVALID_OUTPUT, costMicros: 19_999);

        app(AiUsageGuard::class)->authorize($this->context(), AiRun::FEATURE_PERIOD_SUMMARY);

        $this->recordRun($this->user, AiRun::STATUS_SUCCEEDED, costMicros: 1);

        $this->assertGuardFails(AiException::DISABLED, 503);
        $this->assertDatabaseHas('ai_runs', [
            'organization_id' => $this->organization->id,
            'status' => AiRun::STATUS_QUOTA_EXCEEDED,
            'error_code' => AiException::DISABLED,
            'cost_micros' => 0,
        ]);
        $this->assertSame(50_000, app(AiUsageGuard::class)->monthlySpendMicros());
    }

    public function test_the_monthly_budget_resets_on_the_first_day_of_the_month_in_utc(): void
    {
        config(['ai.monthly_budget_usd' => 0.01]);

        // 21:00 of Oct 31 in São Paulo is already Nov 1 in UTC: October's spend no longer counts.
        Carbon::setTestNow('2026-10-31 23:59:00');
        $this->recordRun($this->user, AiRun::STATUS_SUCCEEDED, costMicros: 10_000);
        $this->assertGuardFails(AiException::DISABLED, 503);

        Carbon::setTestNow('2026-11-01 00:00:00');
        app(AiUsageGuard::class)->authorize($this->context(), AiRun::FEATURE_PERIOD_SUMMARY);
        $this->assertSame(0, app(AiUsageGuard::class)->monthlySpendMicros());
    }

    public function test_a_zero_budget_blocks_every_new_generation(): void
    {
        config(['ai.monthly_budget_usd' => 0]);

        $this->assertGuardFails(AiException::DISABLED, 503);
    }

    public function test_the_budget_never_blocks_reading_cached_summaries(): void
    {
        config(['ai.monthly_budget_usd' => 0]);

        app(AiUsageGuard::class)->ensureAvailable($this->context());

        $this->addToAssertionCount(1);
    }

    public function test_the_demo_is_limited_per_ip_per_day_even_with_one_shared_login(): void
    {
        Carbon::setTestNow('2026-10-05 12:00:00');
        config(['ai.limits.daily_per_organization' => 100, 'ai.limits.daily_per_user' => 100, 'ai.limits.demo_daily_per_ip' => 2]);
        $this->organization->forceFill(['slug' => DemoCommand::ORGANIZATION_SLUG])->save();

        $this->authorizeFrom('198.51.100.7');
        $this->authorizeFrom('198.51.100.7');

        // 12:00 UTC is 09:00 in São Paulo: the counter resets at the demo's midnight, 15 hours later.
        $this->assertGuardFails(AiException::QUOTA_EXCEEDED, 429, ip: '198.51.100.7', expectedRetryAfter: 15 * 3600);
        $this->assertGuardFails(AiException::QUOTA_EXCEEDED, 429, ip: '198.51.100.7');
        $this->assertSame(2, AiRun::query()->where('status', AiRun::STATUS_QUOTA_EXCEEDED)->where('error_code', AiException::QUOTA_EXCEEDED)->count());

        $this->authorizeFrom('203.0.113.20');

        Carbon::setTestNow('2026-10-06 03:00:00');
        $this->authorizeFrom('198.51.100.7');
    }

    public function test_requests_rejected_by_another_limit_do_not_use_the_ip_quota(): void
    {
        config(['ai.limits.daily_per_user' => 1, 'ai.limits.demo_daily_per_ip' => 1]);
        $this->organization->forceFill(['slug' => DemoCommand::ORGANIZATION_SLUG])->save();
        $this->recordRun($this->user, AiRun::STATUS_SUCCEEDED);

        $this->assertGuardFails(AiException::QUOTA_EXCEEDED, 429, ip: '198.51.100.7');

        $visitor = $this->memberOf($this->organization, Organization::ROLE_MEMBER);
        app(AiUsageGuard::class)->authorize($this->context(ip: '198.51.100.7', user: $visitor), AiRun::FEATURE_PERIOD_SUMMARY);
        $this->addToAssertionCount(1);
    }

    public function test_only_the_demo_is_limited_per_ip(): void
    {
        config(['ai.limits.daily_per_organization' => 100, 'ai.limits.daily_per_user' => 100, 'ai.limits.demo_daily_per_ip' => 1]);
        $this->assertFalse($this->organization->isDemo());

        foreach (range(1, 3) as $ignored) {
            $this->authorizeFrom('198.51.100.7');
        }

        $this->assertSame(0, AiRun::query()->count());
    }

    private function context(?string $ip = null, ?User $user = null): AiContext
    {
        return AiContext::for($this->organization->refresh(), $user ?? $this->user, ReportingPeriod::lastDays(30, $this->organization->timezone), ip: $ip);
    }

    private function authorizeFrom(string $ip): void
    {
        app(AiUsageGuard::class)->authorize($this->context($ip), AiRun::FEATURE_PERIOD_SUMMARY);
        $this->addToAssertionCount(1);
    }

    private function recordRun(User $user, string $status, int $costMicros = 0): void
    {
        AiRun::query()->create([...$this->attributes($this->organization, $user, $status), 'cost_micros' => $costMicros]);
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

    private function assertGuardFails(string $code, int $status, ?int $expectedRetryAfter = null, ?string $ip = null): void
    {
        try {
            app(AiUsageGuard::class)->authorize($this->context($ip), AiRun::FEATURE_PERIOD_SUMMARY);
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
