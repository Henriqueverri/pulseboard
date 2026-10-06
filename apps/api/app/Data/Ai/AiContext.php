<?php

namespace App\Data\Ai;

use App\Models\Organization;
use App\Models\User;
use App\Support\Analytics\ReportingPeriod;
use Carbon\CarbonImmutable;

/**
 * Who an AI run is for. Built in the controller from CurrentOrganization: it is
 * the only source of tenant for context builders and tools, which never read an
 * organization from model output or request arguments.
 */
final readonly class AiContext
{
    public function __construct(
        public Organization $organization,
        public ?User $user,
        public ReportingPeriod $period,
        public CarbonImmutable $today,
        public ?string $requestId = null,
        // Only for the demo's per-IP quota; never sent to the provider or stored.
        public ?string $ip = null,
    ) {}

    public static function for(
        Organization $organization,
        ?User $user,
        ReportingPeriod $period,
        ?string $requestId = null,
        ?string $ip = null,
    ): self {
        return new self(
            $organization,
            $user,
            $period,
            CarbonImmutable::now($organization->timezone)->startOfDay(),
            $requestId,
            $ip,
        );
    }
}
