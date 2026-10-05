<?php

namespace App\Services\Ai\Insights;

use App\Data\Ai\AiContext;
use App\Data\Ai\Evidence;
use App\Data\Ai\PeriodSummaryContext;
use App\Http\Requests\Analytics\CustomerAnalyticsRequest;
use App\Http\Requests\Analytics\ProductAnalyticsRequest;
use App\Services\Analytics\CustomerAnalyticsService;
use App\Services\Analytics\DashboardService;
use App\Services\Analytics\ProductAnalyticsService;
use App\Services\Analytics\RevenueAnalyticsService;
use App\Services\Analytics\TransactionStatusAnalyticsService;
use App\Support\Analytics\Granularity;
use App\Support\Analytics\ReportingPeriod;

/**
 * Collects a period's numbers from the existing analytics services (a fixed
 * number of aggregate queries) and turns them into the evidence catalog and
 * the compact context sent to the model. It never computes a metric itself,
 * so the summary cannot disagree with the dashboard.
 *
 * Only aggregates leave this class: the customer ranking (names and e-mails)
 * is discarded, and products are identified by rank, never by id.
 */
final class PeriodSummaryContextBuilder
{
    public const TOP_PRODUCTS = 5;

    public const PRODUCT_NAME_MAX_LENGTH = 80;

    /** Up to this many days the revenue series is daily, then weekly, then monthly (token budget). */
    private const DAILY_MAX_DAYS = 31;

    private const WEEKLY_MAX_DAYS = 182;

    private const STATUS_LABELS = [
        'paid' => ['Transações pagas', Evidence::POLARITY_POSITIVE, PeriodSummarySchema::DESTINATION_TRANSACTIONS],
        'refunded' => ['Transações reembolsadas', Evidence::POLARITY_NEGATIVE, PeriodSummarySchema::DESTINATION_REFUNDED],
        'pending' => ['Transações pendentes', Evidence::POLARITY_NEUTRAL, PeriodSummarySchema::DESTINATION_PENDING],
        'canceled' => ['Transações canceladas', Evidence::POLARITY_NEGATIVE, PeriodSummarySchema::DESTINATION_CANCELED],
    ];

    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly RevenueAnalyticsService $revenue,
        private readonly ProductAnalyticsService $products,
        private readonly CustomerAnalyticsService $customers,
        private readonly TransactionStatusAnalyticsService $statuses,
    ) {}

    public function build(AiContext $context): PeriodSummaryContext
    {
        $organization = $context->organization;
        $period = $context->period;
        $granularity = self::granularityFor($period);

        $kpis = $this->dashboard->kpis($organization, $period);
        $series = $this->revenue->report($organization, $period, $granularity)['series'];
        $products = $this->products->report($organization, $period, ProductAnalyticsRequest::SORT_REVENUE, self::TOP_PRODUCTS);
        $customers = $this->customers->report($organization, $period, CustomerAnalyticsRequest::SORT_REVENUE, 1)['summary'];
        $statuses = $this->statuses->report($organization, $period);

        $catalog = (new EvidenceCatalog)
            ->add(new Evidence('kpi.revenue', 'Receita', Evidence::FORMAT_MONEY, $kpis['revenue'], Evidence::POLARITY_POSITIVE, PeriodSummarySchema::DESTINATION_REVENUE))
            ->add(new Evidence('kpi.orders', 'Pedidos pagos', Evidence::FORMAT_COUNT, $kpis['orders'], Evidence::POLARITY_POSITIVE, PeriodSummarySchema::DESTINATION_REVENUE))
            ->add(new Evidence('kpi.average_order_value', 'Ticket médio', Evidence::FORMAT_MONEY, $kpis['average_order_value'], Evidence::POLARITY_POSITIVE, PeriodSummarySchema::DESTINATION_DASHBOARD))
            ->add(new Evidence('kpi.customers', 'Clientes ativos', Evidence::FORMAT_COUNT, $kpis['customers'], Evidence::POLARITY_POSITIVE, PeriodSummarySchema::DESTINATION_CUSTOMERS))
            ->add(new Evidence('customers.total', 'Base de clientes', Evidence::FORMAT_COUNT, $customers['total_customers'], Evidence::POLARITY_POSITIVE, PeriodSummarySchema::DESTINATION_CUSTOMERS))
            ->add(new Evidence('customers.new', 'Clientes novos', Evidence::FORMAT_COUNT, $customers['new_customers'], Evidence::POLARITY_POSITIVE, PeriodSummarySchema::DESTINATION_CUSTOMERS))
            ->add(new Evidence('customers.returning', 'Clientes recorrentes', Evidence::FORMAT_COUNT, $customers['returning_customers'], Evidence::POLARITY_POSITIVE, PeriodSummarySchema::DESTINATION_CUSTOMERS))
            ->add(new Evidence('products.units_sold', 'Unidades vendidas', Evidence::FORMAT_COUNT, $products['summary']['units_sold'], Evidence::POLARITY_POSITIVE, PeriodSummarySchema::DESTINATION_PRODUCTS))
            ->add(new Evidence('products.products_sold', 'Produtos vendidos', Evidence::FORMAT_COUNT, $products['summary']['products_sold'], Evidence::POLARITY_POSITIVE, PeriodSummarySchema::DESTINATION_PRODUCTS));

        foreach ($statuses as $row) {
            [$label, $polarity, $destination] = self::STATUS_LABELS[$row['status']];
            $catalog->add(new Evidence("status.{$row['status']}", $label, Evidence::FORMAT_COUNT, $row['orders'], $polarity, $destination));
        }

        $untrustedProducts = [];

        foreach ($products['ranking'] as $row) {
            $ref = "product.{$row['rank']}";
            $name = self::clean($row['product']['name'], self::PRODUCT_NAME_MAX_LENGTH);

            $catalog->add(new Evidence($ref, $name, Evidence::FORMAT_MONEY, $row['revenue'], Evidence::POLARITY_POSITIVE, PeriodSummarySchema::DESTINATION_PRODUCTS));

            $untrustedProducts[] = [
                'ref' => $ref,
                'name' => $name,
                'sku' => $row['product']['sku'] === null ? null : self::clean($row['product']['sku']),
                'status' => $row['product']['status'],
                'is_deleted' => $row['product']['is_deleted'],
            ];
        }

        $caveats = PeriodSummaryCaveats::detect($catalog, $period, $context->today, (int) config('ai.insights.low_volume_orders'));
        $previous = $period->previous();

        $data = [
            'currency' => $organization->currency,
            'timezone' => $period->timezone,
            'period' => ['from' => $period->from(), 'to' => $period->to(), 'days' => $period->days()],
            'previous_period' => ['from' => $previous->from(), 'to' => $previous->to(), 'days' => $previous->days()],
            'metrics' => array_map(fn (Evidence $evidence): array => [
                'label' => str_starts_with($evidence->ref, 'product.')
                    ? 'Receita do produto nesta posição do ranking (nome no bloco de produtos)'
                    : $evidence->label,
                'format' => $evidence->format,
                'polarity' => $evidence->polarity,
                ...$evidence->comparison->jsonSerialize(),
            ], $catalog->all()),
            'revenue_series' => [
                'granularity' => $granularity->value,
                'buckets' => array_map(fn (array $bucket): array => [
                    'from' => $bucket['from'],
                    'to' => $bucket['to'],
                    'revenue' => $bucket['revenue'],
                    'orders' => $bucket['orders'],
                ], $series),
            ],
            'caveats' => $caveats,
        ];

        return new PeriodSummaryContext($period, $catalog, $caveats, $data, $untrustedProducts);
    }

    /**
     * A presentation choice that bounds the context size, not a metric definition.
     */
    public static function granularityFor(ReportingPeriod $period): Granularity
    {
        return match (true) {
            $period->days() <= self::DAILY_MAX_DAYS => Granularity::Day,
            $period->days() <= self::WEEKLY_MAX_DAYS => Granularity::Week,
            default => Granularity::Month,
        };
    }

    /**
     * Control characters removed, whitespace collapsed, length optionally capped.
     */
    private static function clean(string $value, ?int $maxLength = null): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\p{Cc}\p{Cf}]/u', ' ', $value)));

        return $maxLength !== null && mb_strlen($value) > $maxLength
            ? rtrim(mb_substr($value, 0, $maxLength - 1)).'…'
            : $value;
    }
}
