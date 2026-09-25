<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    /**
     * Return the organization resolved by EnsureOrganizationContext.
     * Not a full CRUD — context read only for Phase 2.
     */
    public function show(Request $request, CurrentOrganization $current): JsonResponse
    {
        /** @var Organization $organization */
        $organization = $current->organization;

        $this->authorize('view', $organization);

        $organization->setRelation(
            'pivot',
            $request->user()->organizations()->where('organizations.id', $organization->id)->first()?->pivot
        );

        return response()->json([
            'organization' => new OrganizationResource($organization),
        ]);
    }
}
