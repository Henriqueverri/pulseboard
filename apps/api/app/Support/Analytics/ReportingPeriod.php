<?php

namespace App\Support\Analytics;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use DateTimeZone;
use InvalidArgumentException;

/**
 * An inclusive range of calendar days in an organization's timezone.
 *
 * Dates are business-calendar days; the database stores UTC, so queries use
 * startUtc() (inclusive) and endUtc() (exclusive). Boundaries are resolved in
 * the local calendar before converting, so DST days (23h/25h) stay whole.
 */
final class ReportingPeriod
{
    private const DATE_FORMAT = 'Y-m-d';

    /**
     * $from and $to are UTC midnights used only as calendar dates.
     */
    private function __construct(
        private readonly CarbonImmutable $from,
        private readonly CarbonImmutable $to,
        public readonly string $timezone,
    ) {}

    public static function fromDates(string $from, string $to, string $timezone): self
    {
        $fromDate = self::parseDate($from);
        $toDate = self::parseDate($to);

        if ($toDate->lt($fromDate)) {
            throw new InvalidArgumentException("Period end {$to} is before its start {$from}.");
        }

        return new self($fromDate, $toDate, self::validTimezone($timezone));
    }

    /**
     * The last $days calendar days up to and including today in $timezone.
     */
    public static function lastDays(int $days, string $timezone, ?CarbonInterface $now = null): self
    {
        if ($days < 1) {
            throw new InvalidArgumentException('A period must span at least one day.');
        }

        $today = self::parseDate(
            CarbonImmutable::instance($now ?? CarbonImmutable::now())
                ->setTimezone(self::validTimezone($timezone))
                ->format(self::DATE_FORMAT)
        );

        return new self($today->subDays($days - 1), $today, $timezone);
    }

    /**
     * UTC instant at which $date starts in $timezone.
     */
    public static function dayStartUtc(string $date, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!'.self::DATE_FORMAT, self::parseDate($date)->format(self::DATE_FORMAT), $timezone)->utc();
    }

    /**
     * UTC instant at which the day after $date starts in $timezone (exclusive end of $date).
     */
    public static function dayEndUtc(string $date, string $timezone): CarbonImmutable
    {
        return self::dayStartUtc(self::parseDate($date)->addDay()->format(self::DATE_FORMAT), $timezone);
    }

    public function from(): string
    {
        return $this->from->format(self::DATE_FORMAT);
    }

    public function to(): string
    {
        return $this->to->format(self::DATE_FORMAT);
    }

    public function startUtc(): CarbonImmutable
    {
        return self::dayStartUtc($this->from(), $this->timezone);
    }

    public function endUtc(): CarbonImmutable
    {
        return self::dayEndUtc($this->to(), $this->timezone);
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /**
     * The same number of days immediately before this period.
     */
    public function previous(): self
    {
        $to = $this->from->subDay();

        return new self($to->subDays($this->days() - 1), $to, $this->timezone);
    }

    /**
     * First calendar day as a UTC midnight, for calendar arithmetic only.
     */
    public function fromDate(): CarbonImmutable
    {
        return $this->from;
    }

    /**
     * Last calendar day as a UTC midnight, for calendar arithmetic only.
     */
    public function toDate(): CarbonImmutable
    {
        return $this->to;
    }

    private static function parseDate(string $date): CarbonImmutable
    {
        try {
            $parsed = CarbonImmutable::createFromFormat('!'.self::DATE_FORMAT, $date, 'UTC');
        } catch (InvalidFormatException) {
            $parsed = null;
        }

        if (! $parsed instanceof CarbonImmutable || $parsed->format(self::DATE_FORMAT) !== $date) {
            throw new InvalidArgumentException("Invalid calendar date: {$date}.");
        }

        return $parsed;
    }

    private static function validTimezone(string $timezone): string
    {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException("Unknown timezone: {$timezone}.");
        }

        return $timezone;
    }
}
