<?php

namespace App\Http\Requests\Analytics;

use Illuminate\Validation\Rule;

class CustomerAnalyticsRequest extends AnalyticsRequest
{
    public const SORT_REVENUE = 'revenue';

    public const SORT_ORDERS = 'orders';

    public const DEFAULT_LIMIT = 10;

    public const MAX_LIMIT = 50;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'sort' => ['sometimes', Rule::in([self::SORT_REVENUE, self::SORT_ORDERS])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ];
    }

    public function sort(): string
    {
        return $this->validated('sort', self::SORT_REVENUE);
    }

    public function limit(): int
    {
        return (int) $this->validated('limit', self::DEFAULT_LIMIT);
    }
}
