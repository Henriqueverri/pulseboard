<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;

// Everything that happens in an organization (sales, refunds, stock) reaches its members only.
// Membership is checked on every subscription, so a removed member cannot listen anymore.
Broadcast::channel('organization.{organizationId}', function (User $user, string $organizationId): bool {
    return Str::isUuid($organizationId) && $user->belongsToOrganization($organizationId);
});
