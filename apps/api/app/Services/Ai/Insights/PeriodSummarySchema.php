<?php

namespace App\Services\Ai\Insights;

use App\Data\Ai\OutputSchema;

/**
 * Structured output of a period summary (period_summary.v1), in the strict
 * subset of JSON Schema: every field required, no additional properties.
 *
 * `evidence` is an enum of the refs present in this period's catalog, so the
 * model can only cite metrics that exist. Text lengths are not expressible in
 * strict mode and are enforced by PeriodSummaryValidator instead.
 */
final class PeriodSummarySchema
{
    public const NAME = 'period_summary_v1';

    public const KIND_POSITIVE = 'positive';

    public const KIND_NEGATIVE = 'negative';

    public const KIND_NEUTRAL = 'neutral';

    public const KIND_ATTENTION = 'attention';

    public const KINDS = [self::KIND_POSITIVE, self::KIND_NEGATIVE, self::KIND_NEUTRAL, self::KIND_ATTENTION];

    public const DESTINATION_DASHBOARD = 'dashboard';

    public const DESTINATION_REVENUE = 'analytics.revenue';

    public const DESTINATION_PRODUCTS = 'analytics.products';

    public const DESTINATION_CUSTOMERS = 'analytics.customers';

    public const DESTINATION_TRANSACTIONS = 'analytics.transactions';

    public const DESTINATION_REFUNDED = 'transactions.refunded';

    public const DESTINATION_CANCELED = 'transactions.canceled';

    public const DESTINATION_PENDING = 'transactions.pending';

    /**
     * Screens the front can link to (with the same period); the model picks one, never a URL.
     */
    public const DESTINATIONS = [
        self::DESTINATION_DASHBOARD,
        self::DESTINATION_REVENUE,
        self::DESTINATION_PRODUCTS,
        self::DESTINATION_CUSTOMERS,
        self::DESTINATION_TRANSACTIONS,
        self::DESTINATION_REFUNDED,
        self::DESTINATION_CANCELED,
        self::DESTINATION_PENDING,
    ];

    public const HEADLINE_MIN = 10;

    public const HEADLINE_MAX = 140;

    public const OVERVIEW_MIN = 40;

    public const OVERVIEW_MAX = 600;

    public const FINDINGS_MIN = 1;

    public const FINDINGS_MAX = 5;

    public const TITLE_MAX = 90;

    public const EXPLANATION_MAX = 400;

    public const EVIDENCE_MIN = 1;

    public const EVIDENCE_MAX = 3;

    public const ATTENTION_POINTS_MAX = 3;

    public const ATTENTION_TEXT_MAX = 200;

    public static function for(EvidenceCatalog $catalog): OutputSchema
    {
        $evidence = [
            'type' => 'array',
            'minItems' => self::EVIDENCE_MIN,
            'maxItems' => self::EVIDENCE_MAX,
            'items' => ['type' => 'string', 'enum' => $catalog->refs()],
        ];

        return new OutputSchema(self::NAME, [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['headline', 'overview', 'findings', 'attention_points'],
            'properties' => [
                'headline' => ['type' => 'string', 'description' => 'Uma frase, sem números.'],
                'overview' => ['type' => 'string', 'description' => 'Visão geral do período, sem números.'],
                'findings' => [
                    'type' => 'array',
                    'minItems' => self::FINDINGS_MIN,
                    'maxItems' => self::FINDINGS_MAX,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['kind', 'title', 'explanation', 'evidence', 'destination'],
                        'properties' => [
                            'kind' => ['type' => 'string', 'enum' => self::KINDS],
                            'title' => ['type' => 'string'],
                            'explanation' => ['type' => 'string'],
                            'evidence' => $evidence,
                            'destination' => ['type' => 'string', 'enum' => self::DESTINATIONS],
                        ],
                    ],
                ],
                'attention_points' => [
                    'type' => 'array',
                    'minItems' => 0,
                    'maxItems' => self::ATTENTION_POINTS_MAX,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['text', 'evidence'],
                        'properties' => [
                            'text' => ['type' => 'string'],
                            'evidence' => $evidence,
                        ],
                    ],
                ],
            ],
        ]);
    }
}
