<?php

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\RevenueAnalyticsRequest;
use App\Http\Resources\Analytics\RevenueAnalyticsResource;
use App\Models\Transaction;
use App\Services\Analytics\RevenueAnalyticsService;
use App\Support\CurrentOrganization;

class RevenueAnalyticsController extends Controller
{
    public function __invoke(RevenueAnalyticsRequest $request, CurrentOrganization $current, RevenueAnalyticsService $revenue): RevenueAnalyticsResource
    {
        $this->authorize('viewAny', Transaction::class);

        $period = $request->period();
        $granularity = $request->granularity();

        return RevenueAnalyticsResource::make($revenue->report($current->organization, $period, $granularity))
            ->withContext($period, $current->organization)
            ->withGranularity($granularity);
    }
}
