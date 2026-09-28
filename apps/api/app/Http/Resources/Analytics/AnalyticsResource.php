<?php

namespace App\Http\Resources\Analytics;

use App\Models\Organization;
use App\Support\Analytics\ReportingPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/**
 * Shared envelope of dashboard and analytics responses: `data` plus a `meta`
 * describing the reporting period in the organization's business calendar.
 */
abstract class AnalyticsResource extends JsonResource
{
    protected ?ReportingPeriod $period = null;

    protected ?Organization $organization = null;

    public function withContext(ReportingPeriod $period, Organization $organization): static
    {
        $this->period = $period;
        $this->organization = $organization;

        return $this;
    }

    /**
     * Business dates are calendar days in `timezone`, never UTC instants.
     *
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        if ($this->period === null || $this->organization === null) {
            throw new LogicException(static::class.' requires withContext() before serialization.');
        }

        $previous = $this->period->previous();

        return [
            'meta' => [
                'period' => ['from' => $this->period->from(), 'to' => $this->period->to(), 'days' => $this->period->days()],
                'previous_period' => ['from' => $previous->from(), 'to' => $previous->to(), 'days' => $previous->days()],
                'timezone' => $this->period->timezone,
                'currency' => $this->organization->currency,
                ...$this->extraMeta(),
            ],
        ];
    }

    /**
     * Keeps `change` a float in JSON (25.0, not 25).
     */
    public function jsonOptions(): int
    {
        return JSON_PRESERVE_ZERO_FRACTION;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraMeta(): array
    {
        return [];
    }
}
