<?php

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\AnalyticsRequest;
use App\Http\Resources\Analytics\TransactionStatusAnalyticsResource;
use App\Models\Transaction;
use App\Services\Analytics\TransactionStatusAnalyticsService;
use App\Support\CurrentOrganization;

class TransactionStatusAnalyticsController extends Controller
{
    public function __invoke(AnalyticsRequest $request, CurrentOrganization $current, TransactionStatusAnalyticsService $statuses): TransactionStatusAnalyticsResource
    {
        $this->authorize('viewAny', Transaction::class);

        $period = $request->period();

        return TransactionStatusAnalyticsResource::make($statuses->report($current->organization, $period))
            ->withContext($period, $current->organization);
    }
}
