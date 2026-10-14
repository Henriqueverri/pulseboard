<?php

namespace App\Services\Analytics;

use App\Enums\StockStatus;
use App\Models\Organization;
use App\Support\Analytics\ReportingPeriod;
use Carbon\CarbonImmutable;

class InventoryAnalyticsService
{
    /**
     * Four whole weeks, so every weekday weighs the same in the daily average.
     */
    public const WINDOW_DAYS = 28;

    /**
     * Every product with stock control, most urgent first: out of stock, then low, then the
     * rest, each by days of cover. Days of cover divide the balance by the average daily units
     * of paid sales over the window, and are null for products that did not sell in it.
     *
     * One query: the units sold come from a grouped subquery joined to the products.
     *
     * @return array{
     *     window: ReportingPeriod,
     *     summary: array{tracked: int, ok: int, low: int, out: int},
     *     items: list<array{
     *         product: array{id: string, name: string, sku: string|null},
     *         quantity: int,
     *         min_quantity: int,
     *         status: string,
     *         units_sold: int,
     *         average_daily_units: float,
     *         days_of_cover: float|null,
     *         updated_at: string|null
     *     }>
     * }
     */
    public function report(Organization $organization, ?CarbonImmutable $now = null): array
    {
        $window = ReportingPeriod::lastDays(self::WINDOW_DAYS, $organization->timezone, $now);

        $sold = $organization->transactions()
            ->paid()
            ->occurredWithin($window)
            ->join('transaction_items', 'transaction_items.transaction_id', '=', 'transactions.id')
            ->toBase()
            ->select('transaction_items.product_id')
            ->selectRaw('sum(transaction_items.quantity) as units_sold')
            ->groupBy('transaction_items.product_id');

        $rows = $organization->products()
            ->where('products.stock_tracked', true)
            ->leftJoinSub($sold, 'sold', 'sold.product_id', '=', 'products.id')
            ->toBase()
            ->select(['products.id', 'products.name', 'products.sku', 'products.stock_quantity', 'products.stock_min', 'products.stock_updated_at'])
            ->selectRaw('coalesce(sold.units_sold, 0) as units_sold')
            ->get();

        $items = $rows->map(function (object $row): array {
            $quantity = (int) $row->stock_quantity;
            $unitsSold = (int) $row->units_sold;
            $average = $unitsSold / self::WINDOW_DAYS;

            return [
                'product' => ['id' => $row->id, 'name' => $row->name, 'sku' => $row->sku],
                'quantity' => $quantity,
                'min_quantity' => (int) $row->stock_min,
                'status' => StockStatus::of($quantity, (int) $row->stock_min)->value,
                'units_sold' => $unitsSold,
                'average_daily_units' => round($average, 2),
                'days_of_cover' => $unitsSold > 0 ? round($quantity / $average, 1) : null,
                'updated_at' => $row->stock_updated_at === null
                    ? null
                    : CarbonImmutable::parse($row->stock_updated_at, 'UTC')->toIso8601ZuluString(),
            ];
        })->sort(fn (array $a, array $b): int => [
            StockStatus::from($a['status'])->severity(),
            $a['days_of_cover'] ?? INF,
            $a['product']['name'],
            $a['product']['id'],
        ] <=> [
            StockStatus::from($b['status'])->severity(),
            $b['days_of_cover'] ?? INF,
            $b['product']['name'],
            $b['product']['id'],
        ])->values();

        $counts = $items->countBy('status');

        return [
            'window' => $window,
            'summary' => [
                'tracked' => $items->count(),
                'ok' => $counts->get(StockStatus::Ok->value, 0),
                'low' => $counts->get(StockStatus::Low->value, 0),
                'out' => $counts->get(StockStatus::Out->value, 0),
            ],
            'items' => $items->all(),
        ];
    }
}
