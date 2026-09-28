<?php

namespace App\Http\Requests\Analytics;

use App\Support\Analytics\Granularity;
use Illuminate\Validation\Rule;

class RevenueAnalyticsRequest extends AnalyticsRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'granularity' => ['sometimes', Rule::enum(Granularity::class)],
        ];
    }

    public function granularity(): Granularity
    {
        return Granularity::from($this->validated('granularity', Granularity::Day->value));
    }
}
