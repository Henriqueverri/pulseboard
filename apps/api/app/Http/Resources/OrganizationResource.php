<?php

namespace App\Http\Resources;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Organization
 */
class OrganizationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            // available: AI_ENABLED (the global kill switch); enabled: the organization's opt-in.
            'insights' => [
                'available' => (bool) config('ai.enabled'),
                'enabled' => $this->insightsEnabled(),
            ],
            'role' => $this->when(
                isset($this->pivot?->role),
                fn () => $this->pivot->role,
            ),
        ];
    }
}
