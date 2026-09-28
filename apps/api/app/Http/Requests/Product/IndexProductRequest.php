<?php

namespace App\Http\Requests\Product;

use App\Enums\ProductStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexProductRequest extends FormRequest
{
    public const DEFAULT_PER_PAGE = 15;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(ProductStatus::class)],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', self::DEFAULT_PER_PAGE);
    }
}
