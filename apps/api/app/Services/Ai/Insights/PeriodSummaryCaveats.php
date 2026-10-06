<?php

namespace App\Services\Ai\Insights;

use App\Support\Analytics\ReportingPeriod;
use Carbon\CarbonImmutable;

/**
 * Limitations of a period's numbers, decided by the server (never by the
 * model) and attached to every summary of that period.
 */
final class PeriodSummaryCaveats
{
    /** The period ends today or later, so it is still accumulating sales. */
    public const PARTIAL_PERIOD = 'partial_period';

    /** No paid sale in the period. */
    public const NO_SALES = 'no_sales';

    /** No paid sale in the previous period, so variations are undefined. */
    public const NO_PREVIOUS_DATA = 'no_previous_data';

    /** Too few paid orders for a variation to mean a trend. */
    public const LOW_VOLUME = 'low_volume';

    /** Statuses are the current ones: a later refund changes the period it happened in. */
    public const STATUS_IS_CURRENT = 'status_is_current';

    private const STATUS_REFS = ['status.paid', 'status.refunded', 'status.pending', 'status.canceled'];

    /**
     * @return list<string>
     */
    public static function detect(EvidenceCatalog $catalog, ReportingPeriod $period, CarbonImmutable $today, int $lowVolumeOrders): array
    {
        $orders = (int) $catalog->get('kpi.orders')->comparison->value;
        $previousOrders = (int) $catalog->get('kpi.orders')->comparison->previous;
        $transactions = array_sum(array_map(
            fn (string $ref): int => $catalog->has($ref) ? (int) $catalog->get($ref)->comparison->value : 0,
            self::STATUS_REFS,
        ));

        return array_values(array_filter([
            $period->to() >= $today->toDateString() ? self::PARTIAL_PERIOD : null,
            $orders === 0 ? self::NO_SALES : null,
            $previousOrders === 0 ? self::NO_PREVIOUS_DATA : null,
            $orders > 0 && $orders < $lowVolumeOrders ? self::LOW_VOLUME : null,
            $transactions > 0 ? self::STATUS_IS_CURRENT : null,
        ]));
    }
}
