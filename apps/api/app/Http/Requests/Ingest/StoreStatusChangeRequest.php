<?php

namespace App\Http\Requests\Ingest;

use App\Enums\TransactionStatus;
use App\Models\ApiKey;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates the shape of a status change only. Whether the transition itself is
 * allowed belongs to TransactionStatus and TransactionLifecycle.
 */
class StoreStatusChangeRequest extends FormRequest
{
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
            'status' => ['required', 'string', Rule::enum(TransactionStatus::class)],
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

    public function status(): TransactionStatus
    {
        return TransactionStatus::from($this->validated('status'));
    }

    public function occurredAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->validated('occurred_at'))->utc();
    }

    protected function failedValidation(Validator $validator): never
    {
        /** @var ApiKey|null $apiKey */
        $apiKey = $this->attributes->get('api_key');

        Log::info('Transaction status change rejected.', [
            'event' => 'ingest.status_change',
            'outcome' => 'rejected',
            'organization_id' => $apiKey?->organization_id,
            'api_key_id' => $apiKey?->id,
            'api_key_prefix' => $apiKey?->prefix,
            'external_id' => $this->route('externalId'),
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
