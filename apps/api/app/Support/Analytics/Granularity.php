<?php

namespace App\Support\Analytics;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Time-series bucket size. Weeks are ISO weeks (Monday to Sunday).
 */
enum Granularity: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    /**
     * First calendar day of the bucket containing $date.
     */
    public function bucketStart(CarbonImmutable $date): CarbonImmutable
    {
        return match ($this) {
            self::Day => $date->startOfDay(),
            self::Week => $date->startOfWeek(CarbonInterface::MONDAY),
            self::Month => $date->startOfMonth(),
        };
    }

    /**
     * Every bucket overlapping the period, oldest first. `period` is the bucket key
     * (its first calendar day, possibly before the period starts); `from` and `to`
     * are clipped to the period so partial first/last buckets are explicit.
     *
     * @return list<array{period: string, from: string, to: string}>
     */
    public function buckets(ReportingPeriod $period): array
    {
        $buckets = [];
        $first = $period->fromDate();
        $last = $period->toDate();

        for ($start = $this->bucketStart($first); $start->lte($last); $start = $this->next($start)) {
            $end = $this->next($start)->subDay();

            $buckets[] = [
                'period' => $start->toDateString(),
                'from' => $start->max($first)->toDateString(),
                'to' => $end->min($last)->toDateString(),
            ];
        }

        return $buckets;
    }

    private function next(CarbonImmutable $bucketStart): CarbonImmutable
    {
        return match ($this) {
            self::Day => $bucketStart->addDay(),
            self::Week => $bucketStart->addWeek(),
            self::Month => $bucketStart->addMonthNoOverflow(),
        };
    }
}
