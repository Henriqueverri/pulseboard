<?php

namespace Tests\Feature\Ai\Concerns;

use App\Data\Ai\AiContext;
use App\Models\Organization;
use App\Services\Ai\Fake\ScriptedLlmClient;
use App\Services\Ai\Insights\PeriodSummaryContextBuilder;
use App\Services\Ai\Insights\PeriodSummaryService;
use App\Services\Ai\LlmClient;
use App\Support\Analytics\ReportingPeriod;

trait InteractsWithInsights
{
    /**
     * A fresh scripted provider bound in the container, so tests queue answers and inspect requests.
     */
    protected function scriptedProvider(): ScriptedLlmClient
    {
        $client = new ScriptedLlmClient;
        $this->app->instance(LlmClient::class, $client);

        return $client;
    }

    /**
     * The summary lock key of a period, as PeriodSummaryService computes it.
     */
    protected function summaryLockKey(Organization $organization, string $from, string $to): string
    {
        $context = app(PeriodSummaryContextBuilder::class)->build(
            AiContext::for($organization, null, ReportingPeriod::fromDates($from, $to, $organization->timezone)),
        );

        return 'ai:period-summary:'.$organization->id.':'
            .$context->fingerprint(PeriodSummaryService::PROMPT_VERSION, app(LlmClient::class)->provider().'/'.config('ai.model'));
    }

    /**
     * A valid period_summary.v1 answer citing metrics every period has.
     *
     * @return array<string, mixed>
     */
    protected function summaryOutput(string $headline = 'O período manteve as vendas em ritmo estável'): array
    {
        return [
            'headline' => $headline,
            'overview' => 'As vendas pagas seguiram o padrão do período anterior, sem mudanças bruscas nos principais indicadores.',
            'findings' => [
                [
                    'kind' => 'neutral',
                    'title' => 'Receita do período',
                    'explanation' => 'A receita paga é o principal indicador do período.',
                    'evidence' => ['kpi.revenue', 'kpi.orders'],
                    'destination' => 'analytics.revenue',
                ],
            ],
            'attention_points' => [
                ['text' => 'Acompanhe as transações pendentes.', 'evidence' => ['status.pending']],
            ],
        ];
    }
}
