<?php

namespace App\Services\Ai;

use App\Data\Ai\AiContext;
use App\Exceptions\AiException;
use App\Models\AiRun;
use Carbon\CarbonImmutable;

/**
 * Checked before any work that may reach the provider, in order: the global
 * kill switch (AI_ENABLED), the organization's opt-in, then the daily quotas
 * per organization and per user. Quotas count runs that reached the provider
 * (cache hits are free) within the organization's calendar day.
 */
final class AiUsageGuard
{
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

        $dayStart = CarbonImmutable::now($context->organization->timezone)->startOfDay();
        $billableToday = AiRun::query()
            ->forOrganization($context->organization)
            ->where('created_at', '>=', $dayStart->utc())
            ->whereIn('status', AiRun::BILLABLE_STATUSES);

        $organizationRuns = (clone $billableToday)->count();
        $userRuns = $context->user === null ? 0 : (clone $billableToday)->where('user_id', $context->user->id)->count();

        if ($organizationRuns >= (int) config('ai.limits.daily_per_organization')
            || ($context->user !== null && $userRuns >= (int) config('ai.limits.daily_per_user'))) {
            $this->recorder->record(
                $context,
                $feature,
                AiRun::STATUS_QUOTA_EXCEEDED,
                (string) config('ai.provider'),
                (string) config('ai.model'),
                errorCode: AiException::QUOTA_EXCEEDED,
            );

            throw AiException::quotaExceeded((int) CarbonImmutable::now()->diffInSeconds($dayStart->addDay(), true));
        }
    }
}
