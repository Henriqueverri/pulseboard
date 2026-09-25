<?php

namespace App\Support;

use App\Models\Organization;

final class CurrentOrganization
{
    public function __construct(
        public readonly Organization $organization,
    ) {}
}
