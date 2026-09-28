<?php

namespace Tests\Unit\Analytics;

use App\Support\Analytics\Granularity;
use App\Support\Analytics\ReportingPeriod;
use PHPUnit\Framework\TestCase;

class GranularityTest extends TestCase
{
    public function test_daily_buckets_cover_every_day_of_the_period(): void
    {
        $buckets = Granularity::Day->buckets(ReportingPeriod::fromDates('2026-02-27', '2026-03-02', 'America/Sao_Paulo'));

        $this->assertSame(['2026-02-27', '2026-02-28', '2026-03-01', '2026-03-02'], array_column($buckets, 'period'));
        $this->assertSame(['period' => '2026-02-28', 'from' => '2026-02-28', 'to' => '2026-02-28'], $buckets[1]);
    }

    public function test_weekly_buckets_are_iso_weeks_clipped_to_the_period(): void
    {
        // 2026-09-02 is a Wednesday; 2026-09-16 is a Wednesday.
        $buckets = Granularity::Week->buckets(ReportingPeriod::fromDates('2026-09-02', '2026-09-16', 'UTC'));

        $this->assertSame([
            ['period' => '2026-08-31', 'from' => '2026-09-02', 'to' => '2026-09-06'],
            ['period' => '2026-09-07', 'from' => '2026-09-07', 'to' => '2026-09-13'],
            ['period' => '2026-09-14', 'from' => '2026-09-14', 'to' => '2026-09-16'],
        ], $buckets);
    }

    public function test_monthly_buckets_are_calendar_months_clipped_to_the_period(): void
    {
        $buckets = Granularity::Month->buckets(ReportingPeriod::fromDates('2026-01-31', '2026-03-15', 'UTC'));

        $this->assertSame([
            ['period' => '2026-01-01', 'from' => '2026-01-31', 'to' => '2026-01-31'],
            ['period' => '2026-02-01', 'from' => '2026-02-01', 'to' => '2026-02-28'],
            ['period' => '2026-03-01', 'from' => '2026-03-01', 'to' => '2026-03-15'],
        ], $buckets);
    }

    public function test_a_single_day_yields_one_bucket_for_every_granularity(): void
    {
        $period = ReportingPeriod::fromDates('2026-09-13', '2026-09-13', 'UTC');

        foreach (Granularity::cases() as $granularity) {
            $buckets = $granularity->buckets($period);

            $this->assertCount(1, $buckets);
            $this->assertSame(['2026-09-13', '2026-09-13'], [$buckets[0]['from'], $buckets[0]['to']]);
        }

        $this->assertSame('2026-09-07', Granularity::Week->buckets($period)[0]['period']);
    }

    public function test_a_full_year_has_the_expected_bucket_counts(): void
    {
        $period = ReportingPeriod::fromDates('2026-01-01', '2026-12-31', 'UTC');

        $this->assertCount(365, Granularity::Day->buckets($period));
        $this->assertCount(53, Granularity::Week->buckets($period));
        $this->assertCount(12, Granularity::Month->buckets($period));
    }
}
