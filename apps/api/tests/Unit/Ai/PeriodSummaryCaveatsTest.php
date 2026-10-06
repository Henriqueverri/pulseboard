<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Insights\PeriodSummaryCaveats;
use App\Support\Analytics\Comparison;
use App\Support\Analytics\ReportingPeriod;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Ai\Concerns\BuildsPeriodSummaryFixtures;

class PeriodSummaryCaveatsTest extends TestCase
{
    use BuildsPeriodSummaryFixtures;

    private const LOW_VOLUME = 20;

    public function test_a_closed_period_with_enough_sales_only_warns_that_statuses_are_current(): void
    {
        $this->assertSame(
            [PeriodSummaryCaveats::STATUS_IS_CURRENT],
            $this->detect($this->catalog(), '2026-09-01', '2026-09-30', '2026-10-05'),
        );
    }

    public function test_a_period_that_ends_today_or_later_is_partial(): void
    {
        $this->assertContains(PeriodSummaryCaveats::PARTIAL_PERIOD, $this->detect($this->catalog(), '2026-09-06', '2026-10-05', '2026-10-05'));
        $this->assertContains(PeriodSummaryCaveats::PARTIAL_PERIOD, $this->detect($this->catalog(), '2026-10-01', '2026-10-31', '2026-10-05'));
        $this->assertNotContains(PeriodSummaryCaveats::PARTIAL_PERIOD, $this->detect($this->catalog(), '2026-09-05', '2026-10-04', '2026-10-05'));
    }

    public function test_an_empty_period_has_no_sales_no_previous_data_and_nothing_to_warn_about_statuses(): void
    {
        $catalog = $this->catalog([
            'kpi.orders' => Comparison::ofCounts(0, 0),
            'status.paid' => Comparison::ofCounts(0, 0),
            'status.refunded' => Comparison::ofCounts(0, 0),
            'status.pending' => Comparison::ofCounts(0, 0),
            'status.canceled' => Comparison::ofCounts(0, 0),
        ]);

        $this->assertSame(
            [PeriodSummaryCaveats::NO_SALES, PeriodSummaryCaveats::NO_PREVIOUS_DATA],
            $this->detect($catalog, '2020-01-01', '2020-01-31', '2026-10-05'),
        );
    }

    public function test_only_pending_transactions_still_have_current_statuses(): void
    {
        $catalog = $this->catalog([
            'kpi.orders' => Comparison::ofCounts(0, 5),
            'status.paid' => Comparison::ofCounts(0, 5),
            'status.refunded' => Comparison::ofCounts(0, 0),
            'status.pending' => Comparison::ofCounts(2, 0),
            'status.canceled' => Comparison::ofCounts(0, 0),
        ]);

        $this->assertSame(
            [PeriodSummaryCaveats::NO_SALES, PeriodSummaryCaveats::STATUS_IS_CURRENT],
            $this->detect($catalog, '2026-09-01', '2026-09-30', '2026-10-05'),
        );
    }

    public function test_sales_without_a_previous_period_have_no_variation(): void
    {
        $catalog = $this->catalog(['kpi.orders' => Comparison::ofCounts(25, 0)]);

        $this->assertSame(
            [PeriodSummaryCaveats::NO_PREVIOUS_DATA, PeriodSummaryCaveats::STATUS_IS_CURRENT],
            $this->detect($catalog, '2026-09-01', '2026-09-30', '2026-10-05'),
        );
    }

    public function test_low_volume_is_below_the_configured_threshold(): void
    {
        $at = fn (int $orders): array => $this->detect(
            $this->catalog(['kpi.orders' => Comparison::ofCounts($orders, 10)]),
            '2026-09-01',
            '2026-09-30',
            '2026-10-05',
        );

        $this->assertContains(PeriodSummaryCaveats::LOW_VOLUME, $at(1));
        $this->assertContains(PeriodSummaryCaveats::LOW_VOLUME, $at(self::LOW_VOLUME - 1));
        $this->assertNotContains(PeriodSummaryCaveats::LOW_VOLUME, $at(self::LOW_VOLUME));
        $this->assertNotContains(PeriodSummaryCaveats::LOW_VOLUME, $at(0), 'no sales is its own caveat');
    }

    /**
     * @return list<string>
     */
    private function detect(mixed $catalog, string $from, string $to, string $today): array
    {
        return PeriodSummaryCaveats::detect(
            $catalog,
            ReportingPeriod::fromDates($from, $to, 'America/Sao_Paulo'),
            CarbonImmutable::parse($today, 'America/Sao_Paulo'),
            self::LOW_VOLUME,
        );
    }
}
