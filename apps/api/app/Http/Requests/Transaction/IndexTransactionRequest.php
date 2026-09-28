<?php

namespace App\Http\Requests\Transaction;

use App\Enums\TransactionStatus;
use App\Support\Analytics\ReportingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexTransactionRequest extends FormRequest
{
    public const DEFAULT_PER_PAGE = 15;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * customer_id is only format-checked: the listing is always scoped to the
     * current organization, so a foreign customer simply yields no rows.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(TransactionStatus::class)],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer_id' => ['sometimes', 'uuid'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => array_values(array_filter([
                'sometimes',
                'date_format:Y-m-d',
                $this->filled('from') ? 'after_or_equal:from' : null,
            ])),
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to.after_or_equal' => 'The to date must be on or after the from date.',
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', self::DEFAULT_PER_PAGE);
    }

    /**
     * UTC instant at which the `from` day starts in the organization's timezone (inclusive).
     */
    public function occurredFrom(string $timezone): ?CarbonImmutable
    {
        $from = $this->validated('from');

        return $from === null ? null : ReportingPeriod::dayStartUtc($from, $timezone);
    }

    /**
     * UTC instant at which the day after `to` starts in the organization's timezone
     * (exclusive), so the whole `to` day is included.
     */
    public function occurredBefore(string $timezone): ?CarbonImmutable
    {
        $to = $this->validated('to');

        return $to === null ? null : ReportingPeriod::dayEndUtc($to, $timezone);
    }
}
