<?php

namespace App\Support;

use App\Models\Organization;

final class CurrentOrganization
{
    public function __construct(
        public readonly Organization $organization,
    ) {}

    /**
     * The organization bound by EnsureOrganizationContext, or null outside an organization-scoped request.
     */
    public static function resolved(): ?Organization
    {
        return app()->bound(self::class) ? app(self::class)->organization : null;
    }
}
