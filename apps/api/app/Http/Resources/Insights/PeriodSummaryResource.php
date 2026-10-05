<?php

namespace App\Http\Resources\Insights;

use App\Data\Ai\PeriodSummaryResult;
use App\Http\Resources\Analytics\AnalyticsResource;
use App\Services\Ai\Insights\EvidenceCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The model's text with every cited ref resolved from the evidence catalog:
 * the numbers come from the analytics services, never from the model. `data`
 * is null when nothing was generated yet for this period's data.
 *
 * @property PeriodSummaryResult $resource
 */
class PeriodSummaryResource extends AnalyticsResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $content = $this->resource->insight?->content ?? [];
        $catalog = $this->resource->context->catalog;

        return [
            'headline' => $content['headline'],
            'overview' => $content['overview'],
            'findings' => array_map(fn (array $finding): array => [
                'kind' => $finding['kind'],
                'title' => $finding['title'],
                'explanation' => $finding['explanation'],
                'destination' => $finding['destination'],
                'evidence' => self::evidence($finding['evidence'], $catalog),
            ], $content['findings']),
            'attention_points' => array_map(fn (array $point): array => [
                'text' => $point['text'],
                'evidence' => self::evidence($point['evidence'], $catalog),
            ], $content['attention_points']),
            'caveats' => $this->resource->context->caveats,
        ];
    }

    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse
    {
        if ($this->resource->insight === null) {
            return new JsonResponse(['data' => null, ...$this->with($request)], 200, [], $this->jsonOptions());
        }

        return parent::toResponse($request);
    }

    /**
     * @return array{insight: array{generated_at: mixed, model: string, prompt_version: string, cached: bool}|null}
     */
    protected function extraMeta(): array
    {
        $insight = $this->resource->insight;

        return [
            'insight' => $insight === null ? null : [
                'generated_at' => $insight->created_at,
                'model' => $insight->model,
                'prompt_version' => $insight->prompt_version,
                'cached' => $this->resource->cached,
            ],
        ];
    }

    /**
     * @param  list<string>  $refs
     * @return list<array<string, mixed>>
     */
    private static function evidence(array $refs, EvidenceCatalog $catalog): array
    {
        return array_values(array_map(
            fn (string $ref): array => $catalog->get($ref)->toArray(),
            array_filter($refs, $catalog->has(...)),
        ));
    }
}
