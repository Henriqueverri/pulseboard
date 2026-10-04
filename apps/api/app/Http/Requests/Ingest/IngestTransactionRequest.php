<?php

namespace App\Http\Requests\Ingest;

use App\Data\Ingestion\IngestTransactionData;
use App\Models\ApiKey;
use App\Support\ExternalId;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class IngestTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('customer.email'))) {
            $customer = $this->input('customer');
            $customer['email'] = Str::lower($customer['email']);
            $this->merge(['customer' => $customer]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'id' => ['prohibited'],
            'organization_id' => ['prohibited'],
            'external_id' => ['required', ...ExternalId::rules()],
            'status' => ['required', 'string', Rule::in(['pending', 'paid'])],
            'occurred_at' => [
                'required',
                'string',
                'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/',
                'date',
                'before_or_equal:'.now()->addMinutes(5)->toIso8601String(),
            ],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'customer' => ['required', 'array:external_id,name,email'],
            'customer.external_id' => ['required', ...ExternalId::rules()],
            'customer.name' => ['required', 'string', 'max:255'],
            'customer.email' => ['required', 'string', 'email', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:sku,quantity,unit_price'],
            'items.*.sku' => ['required', 'string', 'max:64', 'distinct:strict'],
            'items.*.quantity' => ['required', 'integer', 'between:1,10000'],
            'items.*.unit_price' => ['required', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'total_amount' => ['sometimes', 'required', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'external_id.regex' => 'The external id may only contain letters, numbers, dots, underscores, colons and hyphens.',
            'customer.external_id.regex' => 'The customer external id may only contain letters, numbers, dots, underscores, colons and hyphens.',
            'occurred_at.regex' => 'The occurred at field must be an ISO 8601 datetime with a timezone offset.',
            'items.*.sku.distinct' => 'Each product SKU may appear only once.',
            'items.*.unit_price.string' => 'The unit price must be sent as a decimal string.',
            'items.*.unit_price.regex' => 'The unit price must be a non-negative decimal string with at most two decimal places.',
            'total_amount.string' => 'The total amount must be sent as a decimal string.',
            'total_amount.regex' => 'The total amount must be a non-negative decimal string with at most two decimal places.',
        ];
    }

    public function transactionData(): IngestTransactionData
    {
        return IngestTransactionData::fromValidated($this->validated());
    }

    protected function failedValidation(Validator $validator): never
    {
        /** @var ApiKey|null $apiKey */
        $apiKey = $this->attributes->get('api_key');

        Log::info('Transaction ingestion rejected.', [
            'event' => 'ingest.transaction',
            'outcome' => 'rejected',
            'organization_id' => $apiKey?->organization_id,
            'api_key_id' => $apiKey?->id,
            'api_key_prefix' => $apiKey?->prefix,
            'external_id' => is_string($this->input('external_id')) ? $this->input('external_id') : null,
            'items_count' => is_array($this->input('items')) ? count($this->input('items')) : null,
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
