<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\UpdateNotificationsRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;

class OrganizationNotificationsController extends Controller
{
    /**
     * The daily revenue goal whose achievement is pushed to the members' phones.
     */
    public function update(UpdateNotificationsRequest $request, CurrentOrganization $current): JsonResponse
    {
        /** @var Organization $organization */
        $organization = $current->organization;

        $this->authorize('update', $organization);

        $goal = $request->validated('daily_revenue_goal');

        $organization->forceFill([
            'daily_revenue_goal' => $goal === null ? null : (string) $goal,
        ])->save();

        $organization->setRelation(
            'pivot',
            $request->user()->organizations()->where('organizations.id', $organization->id)->first()?->pivot
        );

        return response()->json([
            'organization' => new OrganizationResource($organization),
        ]);
    }
}
