<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationsRequest extends FormRequest
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
            // null removes the goal.
            'daily_revenue_goal' => ['present', 'nullable', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
        ];
    }
}
