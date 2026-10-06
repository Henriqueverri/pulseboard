<?php

namespace Tests\Unit\Ai\Concerns;

use App\Data\Ai\Evidence;
use App\Services\Ai\Insights\EvidenceCatalog;
use App\Services\Ai\Insights\PeriodSummarySchema;
use App\Support\Analytics\Comparison;

trait BuildsPeriodSummaryFixtures
{
    /**
     * Revenue up, orders down, refunds up, cancellations down, pending stable.
     *
     * @param  array<string, Comparison>  $overrides
     */
    protected function catalog(array $overrides = [], int $products = 1): EvidenceCatalog
    {
        $positive = Evidence::POLARITY_POSITIVE;
        $entries = [
            'kpi.revenue' => [Evidence::FORMAT_MONEY, Comparison::ofMoney(1200000, 1000000), $positive],
            'kpi.orders' => [Evidence::FORMAT_COUNT, Comparison::ofCounts(40, 50), $positive],
            'kpi.average_order_value' => [Evidence::FORMAT_MONEY, Comparison::ofMoney(30000, 20000), $positive],
            'kpi.customers' => [Evidence::FORMAT_COUNT, Comparison::ofCounts(30, 30), $positive],
            'customers.total' => [Evidence::FORMAT_COUNT, Comparison::ofCounts(200, 190), $positive],
            'customers.new' => [Evidence::FORMAT_COUNT, Comparison::ofCounts(10, 12), $positive],
            'customers.returning' => [Evidence::FORMAT_COUNT, Comparison::ofCounts(20, 18), $positive],
            'products.units_sold' => [Evidence::FORMAT_COUNT, Comparison::ofCounts(90, 100), $positive],
            'products.products_sold' => [Evidence::FORMAT_COUNT, Comparison::ofCounts(12, 12), $positive],
            'status.paid' => [Evidence::FORMAT_COUNT, Comparison::ofCounts(40, 50), $positive],
            'status.refunded' => [Evidence::FORMAT_COUNT, Comparison::ofCounts(4, 2), Evidence::POLARITY_NEGATIVE],
            'status.pending' => [Evidence::FORMAT_COUNT, Comparison::ofCounts(6, 3), Evidence::POLARITY_NEUTRAL],
            'status.canceled' => [Evidence::FORMAT_COUNT, Comparison::ofCounts(1, 2), Evidence::POLARITY_NEGATIVE],
        ];

        for ($rank = 1; $rank <= $products; $rank++) {
            $entries["product.{$rank}"] = [Evidence::FORMAT_MONEY, Comparison::ofMoney(50000 - $rank, 40000), $positive];
        }

        $catalog = new EvidenceCatalog;

        foreach ($entries as $ref => [$format, $comparison, $polarity]) {
            $catalog->add(new Evidence($ref, $ref, $format, $overrides[$ref] ?? $comparison, $polarity, PeriodSummarySchema::DESTINATION_DASHBOARD));
        }

        return $catalog;
    }

    /**
     * @return array<string, mixed>
     */
    protected function validOutput(): array
    {
        return [
            'headline' => 'A receita cresceu mesmo com menos pedidos pagos',
            'overview' => 'O período teve receita maior que o anterior, puxada por um ticket médio mais alto, enquanto os pedidos pagos diminuíram.',
            'findings' => [
                [
                    'kind' => 'positive',
                    'title' => 'Receita e ticket médio em alta',
                    'explanation' => 'Cada pedido pago rendeu mais do que no período anterior.',
                    'evidence' => ['kpi.revenue', 'kpi.average_order_value'],
                    'destination' => 'analytics.revenue',
                ],
                [
                    'kind' => 'negative',
                    'title' => 'Menos pedidos pagos',
                    'explanation' => 'O volume de pedidos pagos caiu em relação ao período anterior.',
                    'evidence' => ['kpi.orders'],
                    'destination' => 'analytics.revenue',
                ],
            ],
            'attention_points' => [
                ['text' => 'Os reembolsos aumentaram; vale revisar as transações reembolsadas.', 'evidence' => ['status.refunded']],
            ],
        ];
    }
}
