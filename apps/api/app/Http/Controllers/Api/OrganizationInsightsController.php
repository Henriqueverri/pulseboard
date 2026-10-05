<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\UpdateInsightsRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;

class OrganizationInsightsController extends Controller
{
    /**
     * The owner's opt-in. Enabling requires AI_ENABLED; disabling is always
     * allowed, so an organization can opt out even while the feature is off.
     */
    public function update(UpdateInsightsRequest $request, CurrentOrganization $current): JsonResponse
    {
        /** @var Organization $organization */
        $organization = $current->organization;

        $this->authorize('update', $organization);

        if ($request->boolean('enabled')) {
            if (! config('ai.enabled')) {
                throw AiException::disabled();
            }

            $organization->enableInsights($request->user());
        } else {
            $organization->disableInsights();
        }

        $organization->setRelation(
            'pivot',
            $request->user()->organizations()->where('organizations.id', $organization->id)->first()?->pivot
        );

        return response()->json([
            'organization' => new OrganizationResource($organization),
        ]);
    }
}
