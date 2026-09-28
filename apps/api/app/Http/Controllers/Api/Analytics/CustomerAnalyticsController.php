<?php

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\CustomerAnalyticsRequest;
use App\Http\Resources\Analytics\CustomerAnalyticsResource;
use App\Models\Transaction;
use App\Services\Analytics\CustomerAnalyticsService;
use App\Support\CurrentOrganization;

class CustomerAnalyticsController extends Controller
{
    public function __invoke(CustomerAnalyticsRequest $request, CurrentOrganization $current, CustomerAnalyticsService $customers): CustomerAnalyticsResource
    {
        $this->authorize('viewAny', Transaction::class);

        $period = $request->period();
        $sort = $request->sort();
        $limit = $request->limit();

        return CustomerAnalyticsResource::make($customers->report($current->organization, $period, $sort, $limit))
            ->withContext($period, $current->organization)
            ->withRanking($sort, $limit);
    }
}
