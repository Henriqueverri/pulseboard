<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrganizationContext
{
    /**
     * Resolve and authorize the organization context from X-Organization-Id.
     *
     * The header selects context only — membership is always re-validated.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = $request->header('X-Organization-Id');

        if (! is_string($organizationId) || $organizationId === '') {
            return response()->json([
                'message' => 'The X-Organization-Id header is required.',
            ], 400);
        }

        if (! Str::isUuid($organizationId)) {
            return response()->json([
                'message' => 'The X-Organization-Id header must be a valid UUID.',
            ], 400);
        }

        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $organization = Organization::query()->find($organizationId);

        if ($organization === null || ! $user->belongsToOrganization($organization)) {
            return response()->json([
                'message' => 'You do not have access to this organization.',
            ], 403);
        }

        $current = new CurrentOrganization($organization);

        app()->instance(CurrentOrganization::class, $current);
        $request->attributes->set('organization', $organization);

        return $next($request);
    }
}
