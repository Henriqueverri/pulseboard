<?php

namespace App\Http\Resources\Analytics;

use App\Models\Organization;
use App\Support\Analytics\Comparison;
use App\Support\Analytics\ReportingPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/**
 * @property array{revenue: Comparison, orders: Comparison, average_order_value: Comparison, customers: Comparison} $resource
 */
class DashboardResource extends JsonResource
{
    private ?ReportingPeriod $period = null;

    private ?Organization $organization = null;

    public function withContext(ReportingPeriod $period, Organization $organization): static
    {
        $this->period = $period;
        $this->organization = $organization;

        return $this;
    }

    /**
     * @return array<string, array{value: int|string|null, previous: int|string|null, change: float|null}>
     */
    public function toArray(Request $request): array
    {
        return [
            'revenue' => $this->resource['revenue']->jsonSerialize(),
            'orders' => $this->resource['orders']->jsonSerialize(),
            'average_order_value' => $this->resource['average_order_value']->jsonSerialize(),
            'customers' => $this->resource['customers']->jsonSerialize(),
        ];
    }

    /**
     * Business dates are calendar days in `timezone`, never UTC instants.
     *
     * @return array{meta: array<string, mixed>}
     */
    public function with(Request $request): array
    {
        if ($this->period === null || $this->organization === null) {
            throw new LogicException('DashboardResource requires withContext() before serialization.');
        }

        $previous = $this->period->previous();

        return [
            'meta' => [
                'period' => ['from' => $this->period->from(), 'to' => $this->period->to(), 'days' => $this->period->days()],
                'previous_period' => ['from' => $previous->from(), 'to' => $previous->to(), 'days' => $previous->days()],
                'timezone' => $this->period->timezone,
                'currency' => $this->organization->currency,
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
}
