<?php

namespace App\Services\Ai;

use App\Data\Ai\AiContext;
use App\Exceptions\AiException;
use App\Models\AiRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Checked before any work that may reach the provider, in order: the global
 * kill switch (AI_ENABLED), the organization's opt-in, the global monthly
 * budget, the daily quotas per organization and per user, then (demo only) the
 * daily quota per IP. Quotas count runs that reached the provider (cache hits
 * are free) within the organization's calendar day.
 */
final class AiUsageGuard
{
    private const DEMO_IP_KEY_PREFIX = 'ai:demo-ip:';

    public function __construct(
        private readonly AiRunRecorder $recorder,
    ) {}

    public function ensureAvailable(AiContext $context): void
    {
        if (! config('ai.enabled')) {
            throw AiException::disabled();
        }

        if (! $context->organization->insightsEnabled()) {
            throw AiException::notEnabled();
        }
    }

    /**
     * @throws AiException ai_disabled, ai_not_enabled or ai_quota_exceeded
     */
    public function authorize(AiContext $context, string $feature): void
    {
        $this->ensureAvailable($context);

        if ($this->monthlySpendMicros() >= $this->monthlyBudgetMicros()) {
            $this->reject($context, $feature, AiException::DISABLED);

            throw AiException::disabled();
        }

        $dayStart = CarbonImmutable::now($context->organization->timezone)->startOfDay();
        $secondsToReset = (int) CarbonImmutable::now()->diffInSeconds($dayStart->addDay(), true);
        $billableToday = AiRun::query()
            ->forOrganization($context->organization)
            ->where('created_at', '>=', $dayStart->utc())
            ->whereIn('status', AiRun::BILLABLE_STATUSES);

        $organizationRuns = (clone $billableToday)->count();
        $userRuns = $context->user === null ? 0 : (clone $billableToday)->where('user_id', $context->user->id)->count();

        if ($organizationRuns >= (int) config('ai.limits.daily_per_organization')
            || ($context->user !== null && $userRuns >= (int) config('ai.limits.daily_per_user'))) {
            $this->reject($context, $feature, AiException::QUOTA_EXCEEDED);

            throw AiException::quotaExceeded($secondsToReset);
        }

        $ipKey = $this->demoIpKey($context);

        if ($ipKey === null) {
            return;
        }

        if (RateLimiter::tooManyAttempts($ipKey, (int) config('ai.limits.demo_daily_per_ip'))) {
            $this->reject($context, $feature, AiException::QUOTA_EXCEEDED);

            throw AiException::quotaExceeded(RateLimiter::availableIn($ipKey));
        }

        RateLimiter::hit($ipKey, max(1, $secondsToReset));
    }

    /**
     * Estimated cost of every organization's runs in the current calendar month (UTC).
     */
    public function monthlySpendMicros(): int
    {
        return (int) AiRun::query()
            ->where('created_at', '>=', CarbonImmutable::now('UTC')->startOfMonth())
            ->sum('cost_micros');
    }

    public function monthlyBudgetMicros(): int
    {
        return (int) round(max(0.0, (float) config('ai.monthly_budget_usd')) * 1_000_000);
    }

    /**
     * The demo login is shared, so its per-user quota does not hold back one
     * visitor; the per-IP counter does. Only the hash of the IP is kept, in the
     * cache, until the organization's midnight.
     */
    private function demoIpKey(AiContext $context): ?string
    {
        if (! $context->organization->isDemo() || $context->ip === null || $context->ip === '') {
            return null;
        }

        return self::DEMO_IP_KEY_PREFIX.hash('sha256', $context->ip);
    }

    private function reject(AiContext $context, string $feature, string $errorCode): void
    {
        $this->recorder->record(
            $context,
            $feature,
            AiRun::STATUS_QUOTA_EXCEEDED,
            (string) config('ai.provider'),
            (string) config('ai.model'),
            errorCode: $errorCode,
        );
    }
}
