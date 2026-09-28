<?php

namespace App\Http\Requests\Product;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(CurrentOrganization $current): array
    {
        /** @var Product $product */
        $product = $this->route('product');

        return [
            'id' => ['prohibited'],
            'organization_id' => ['prohibited'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'sku' => [
                'sometimes',
                'nullable',
                'string',
                'max:64',
                Rule::unique('products', 'sku')
                    ->where('organization_id', $current->organization->id)
                    ->ignore($product->id),
            ],
            'price' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'status' => ['sometimes', 'required', Rule::enum(ProductStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'id.prohibited' => 'The id cannot be changed.',
            'organization_id.prohibited' => 'A product cannot be moved to another organization.',
            'sku.unique' => 'This SKU is already used by another product in this organization, including deleted products.',
        ];
    }
}
