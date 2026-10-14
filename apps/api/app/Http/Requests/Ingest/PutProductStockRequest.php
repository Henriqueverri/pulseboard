<?php

namespace App\Http\Requests\Ingest;

use App\Data\Ingestion\ProductStockData;
use App\Models\ApiKey;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PutProductStockRequest extends FormRequest
{
    private const MAX_QUANTITY = 2_147_483_647;

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
            'tracked' => ['required', 'boolean:strict'],
            'quantity' => ['required', 'integer', 'min:0', 'max:'.self::MAX_QUANTITY],
            'min_quantity' => ['required', 'integer', 'min:0', 'max:'.self::MAX_QUANTITY],
            'occurred_at' => [
                'required',
                'string',
                'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/',
                'date',
                'before_or_equal:'.now()->addMinutes(5)->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'occurred_at.regex' => 'The occurred at field must be an ISO 8601 datetime with a timezone offset.',
        ];
    }

    public function stockData(): ProductStockData
    {
        return new ProductStockData(
            tracked: $this->validated('tracked'),
            quantity: (int) $this->validated('quantity'),
            minQuantity: (int) $this->validated('min_quantity'),
            occurredAt: CarbonImmutable::parse($this->validated('occurred_at'))->utc(),
        );
    }

    protected function failedValidation(Validator $validator): never
    {
        /** @var ApiKey|null $apiKey */
        $apiKey = $this->attributes->get('api_key');

        Log::info('Product stock update rejected.', [
            'event' => 'ingest.product_stock',
            'outcome' => 'rejected',
            'organization_id' => $apiKey?->organization_id,
            'api_key_id' => $apiKey?->id,
            'api_key_prefix' => $apiKey?->prefix,
            'sku' => $this->route('sku'),
            'http_status' => Response::HTTP_UNPROCESSABLE_ENTITY,
            'code' => 'validation_failed',
        ]);

        throw new HttpResponseException(response()->json([
            'message' => 'The given data was invalid.',
            'code' => 'validation_failed',
            'errors' => $validator->errors(),
        ], Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
