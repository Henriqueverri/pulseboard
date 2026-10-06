<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInsightsRequest extends FormRequest
{
    /**
     * Owners only, checked in the controller with OrganizationPolicy::update.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'organization_id' => ['prohibited'],
            'enabled' => ['required', 'boolean'],
        ];
    }
}
