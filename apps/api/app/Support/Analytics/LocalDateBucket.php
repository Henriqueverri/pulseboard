<?php

namespace App\Support\Analytics;

use Illuminate\Database\Connection;
use LogicException;

/**
 * SQL expression mapping a UTC timestamp column to the `Y-m-d` key of its bucket
 * in the period's timezone (see Granularity::buckets() for the matching keys).
 *
 * PostgreSQL converts with the IANA timezone database, so DST is exact.
 * SQLite has no timezone support: it applies the fixed UTC offset in effect at the
 * start of the period, which is exact for zones without DST (e.g. America/Sao_Paulo)
 * and only an approximation otherwise. SQLite is used for tests, not production.
 *
 * Select the expression under an alias and group by that alias: repeating it in
 * GROUP BY binds the timezone as a second parameter, which PostgreSQL rejects
 * as a different expression.
 */
final class LocalDateBucket
{
    /**
     * @return array{0: string, 1: list<string>} SQL and its bindings
     */
    public static function expression(Connection $connection, string $column, Granularity $granularity, ReportingPeriod $period): array
    {
        $column = $connection->getQueryGrammar()->wrap($column);

        return match ($connection->getDriverName()) {
            'pgsql' => [
                "to_char(date_trunc('{$granularity->value}', {$column} AT TIME ZONE 'UTC' AT TIME ZONE ?), 'YYYY-MM-DD')",
                [$period->timezone],
            ],
            'sqlite' => self::sqlite($column, $granularity, $period),
            default => throw new LogicException("Local date buckets are not supported on [{$connection->getDriverName()}]."),
        };
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private static function sqlite(string $column, Granularity $granularity, ReportingPeriod $period): array
    {
        $offset = $period->startUtc()->setTimezone($period->timezone)->getOffset();
        $shift = sprintf('%+d seconds', $offset);

        return match ($granularity) {
            Granularity::Day => ["date({$column}, ?)", [$shift]],
            Granularity::Week => ["date({$column}, ?, 'weekday 0', '-6 days')", [$shift]],
            Granularity::Month => ["date({$column}, ?, 'start of month')", [$shift]],
        };
    }
}
