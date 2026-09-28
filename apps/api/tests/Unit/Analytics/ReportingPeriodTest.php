<?php

namespace Tests\Unit\Analytics;

use App\Support\Analytics\ReportingPeriod;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReportingPeriodTest extends TestCase
{
    public function test_sao_paulo_days_are_converted_to_utc_boundaries(): void
    {
        $period = ReportingPeriod::fromDates('2026-09-01', '2026-09-30', 'America/Sao_Paulo');

        $this->assertSame('2026-09-01', $period->from());
        $this->assertSame('2026-09-30', $period->to());
        $this->assertSame('2026-09-01 03:00:00', $period->startUtc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-01 03:00:00', $period->endUtc()->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $period->startUtc()->getTimezone()->getName());
        $this->assertSame(30, $period->days());
    }

    public function test_lisbon_boundaries_follow_daylight_saving_time(): void
    {
        $summer = ReportingPeriod::fromDates('2026-09-01', '2026-09-30', 'Europe/Lisbon');
        $this->assertSame('2026-08-31 23:00:00', $summer->startUtc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-30 23:00:00', $summer->endUtc()->format('Y-m-d H:i:s'));

        $winter = ReportingPeriod::fromDates('2026-01-10', '2026-01-10', 'Europe/Lisbon');
        $this->assertSame('2026-01-10 00:00:00', $winter->startUtc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-11 00:00:00', $winter->endUtc()->format('Y-m-d H:i:s'));
    }

    public function test_dst_transition_days_keep_their_real_length(): void
    {
        $springForward = ReportingPeriod::fromDates('2026-03-29', '2026-03-29', 'Europe/Lisbon');
        $this->assertSame(23.0, $springForward->startUtc()->diffInHours($springForward->endUtc()));
        $this->assertSame(1, $springForward->days());

        $fallBack = ReportingPeriod::fromDates('2026-10-25', '2026-10-25', 'Europe/Lisbon');
        $this->assertSame(25.0, $fallBack->startUtc()->diffInHours($fallBack->endUtc()));
    }

    public function test_previous_period_has_the_same_length_immediately_before(): void
    {
        $previous = ReportingPeriod::fromDates('2026-09-01', '2026-09-30', 'America/Sao_Paulo')->previous();

        $this->assertSame('2026-08-02', $previous->from());
        $this->assertSame('2026-08-31', $previous->to());
        $this->assertSame(30, $previous->days());
        $this->assertSame('America/Sao_Paulo', $previous->timezone);
        $this->assertSame('2026-09-01 03:00:00', $previous->endUtc()->format('Y-m-d H:i:s'));
    }

    public function test_previous_period_crosses_dst_and_year_boundaries(): void
    {
        $single = ReportingPeriod::fromDates('2026-03-30', '2026-03-30', 'Europe/Lisbon')->previous();
        $this->assertSame(['2026-03-29', '2026-03-29'], [$single->from(), $single->to()]);

        $yearly = ReportingPeriod::fromDates('2026-01-01', '2026-01-07', 'UTC')->previous();
        $this->assertSame(['2025-12-25', '2025-12-31'], [$yearly->from(), $yearly->to()]);
    }

    public function test_last_days_ends_today_in_the_organization_timezone(): void
    {
        // 01:30 UTC on Oct 1st is still Sep 30th in São Paulo.
        $now = CarbonImmutable::parse('2026-10-01 01:30:00', 'UTC');

        $saoPaulo = ReportingPeriod::lastDays(30, 'America/Sao_Paulo', $now);
        $this->assertSame(['2026-09-01', '2026-09-30'], [$saoPaulo->from(), $saoPaulo->to()]);

        $utc = ReportingPeriod::lastDays(30, 'UTC', $now);
        $this->assertSame(['2026-09-02', '2026-10-01'], [$utc->from(), $utc->to()]);

        $today = ReportingPeriod::lastDays(1, 'America/Sao_Paulo', $now);
        $this->assertSame(1, $today->days());
    }

    public function test_single_day_helpers_return_utc_instants(): void
    {
        $this->assertSame('2026-09-10 03:00:00', ReportingPeriod::dayStartUtc('2026-09-10', 'America/Sao_Paulo')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-11 03:00:00', ReportingPeriod::dayEndUtc('2026-09-10', 'America/Sao_Paulo')->format('Y-m-d H:i:s'));
        $this->assertSame('2027-01-01 03:00:00', ReportingPeriod::dayEndUtc('2026-12-31', 'America/Sao_Paulo')->format('Y-m-d H:i:s'));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function invalidPeriods(): array
    {
        return [
            'end before start' => ['2026-09-10', '2026-09-01', 'UTC'],
            'overflowing date' => ['2026-02-30', '2026-03-01', 'UTC'],
            'wrong format' => ['10/09/2026', '2026-09-30', 'UTC'],
            'unknown timezone' => ['2026-09-01', '2026-09-30', 'Mars/Olympus'],
        ];
    }

    #[DataProvider('invalidPeriods')]
    public function test_invalid_periods_are_rejected(string $from, string $to, string $timezone): void
    {
        $this->expectException(InvalidArgumentException::class);

        ReportingPeriod::fromDates($from, $to, $timezone);
    }
}
