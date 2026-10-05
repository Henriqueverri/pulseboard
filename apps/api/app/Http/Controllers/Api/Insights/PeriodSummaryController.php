<?php

namespace App\Http\Controllers\Api\Insights;

use App\Data\Ai\AiContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Insights\PeriodSummaryRequest;
use App\Http\Resources\Insights\PeriodSummaryResource;
use App\Models\Transaction;
use App\Services\Ai\Insights\PeriodSummaryService;
use App\Support\CurrentOrganization;

class PeriodSummaryController extends Controller
{
    /**
     * The summary already generated for the period's current data, or `data: null`.
     * Never calls the AI provider.
     */
    public function show(PeriodSummaryRequest $request, CurrentOrganization $current, PeriodSummaryService $summaries): PeriodSummaryResource
    {
        $this->authorize('viewAny', Transaction::class);

        $context = $this->context($request, $current);

        return PeriodSummaryResource::make($summaries->cached($context))
            ->withContext($context->period, $current->organization);
    }

    /**
     * Generates the summary, or serves the cached one when the data did not change.
     */
    public function store(PeriodSummaryRequest $request, CurrentOrganization $current, PeriodSummaryService $summaries): PeriodSummaryResource
    {
        $this->authorize('viewAny', Transaction::class);

        $context = $this->context($request, $current);

        return PeriodSummaryResource::make($summaries->generate($context))
            ->withContext($context->period, $current->organization);
    }

    /**
     * The tenant comes from CurrentOrganization only, never from the request body.
     */
    private function context(PeriodSummaryRequest $request, CurrentOrganization $current): AiContext
    {
        return AiContext::for(
            $current->organization,
            $request->user(),
            $request->period(),
            $request->attributes->get('request_id'),
        );
    }
}
