<?php

namespace App\Http\Requests\Analytics;

use App\Support\Analytics\ReportingPeriod;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Reporting period shared by dashboard and analytics endpoints.
 *
 * `from` / `to` are calendar days in the organization's timezone; the timezone
 * is never taken from the client. Without both dates, the last 30 days (today included).
 */
class AnalyticsRequest extends FormRequest
{
    public const DEFAULT_DAYS = 30;

    public const MAX_DAYS = 366;

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
            'organization_id' => ['prohibited'],
            'from' => ['required_with:to', 'date_format:Y-m-d'],
            'to' => array_values(array_filter([
                'required_with:from',
                'date_format:Y-m-d',
                $this->filled('from') ? 'after_or_equal:from' : null,
            ])),
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

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['from', 'to']) || ! $this->filled(['from', 'to'])) {
                    return;
                }

                $days = ReportingPeriod::fromDates($this->input('from'), $this->input('to'), 'UTC')->days();

                if ($days > self::MAX_DAYS) {
                    $validator->errors()->add('to', 'The period may not be longer than '.self::MAX_DAYS.' days.');
                }
            },
        ];
    }

    public function period(): ReportingPeriod
    {
        $timezone = app(CurrentOrganization::class)->organization->timezone;

        $from = $this->validated('from');
        $to = $this->validated('to');

        return $from === null || $to === null
            ? ReportingPeriod::lastDays(self::DEFAULT_DAYS, $timezone)
            : ReportingPeriod::fromDates($from, $to, $timezone);
    }
}
