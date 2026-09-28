<?php

namespace App\Http\Requests\Customer;

use App\Models\Customer;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower($this->input('email'))]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(CurrentOrganization $current): array
    {
        /** @var Customer $customer */
        $customer = $this->route('customer');

        return [
            'id' => ['prohibited'],
            'organization_id' => ['prohibited'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('customers', 'email')
                    ->where('organization_id', $current->organization->id)
                    ->ignore($customer->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'id.prohibited' => 'The id cannot be changed.',
            'organization_id.prohibited' => 'A customer cannot be moved to another organization.',
            'email.unique' => 'This email is already used by another customer in this organization, including deleted customers.',
        ];
    }
}
