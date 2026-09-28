<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\AnalyticsRequest;
use App\Http\Resources\Analytics\DashboardResource;
use App\Models\Transaction;
use App\Services\Analytics\DashboardService;
use App\Support\CurrentOrganization;

class DashboardController extends Controller
{
    public function __invoke(AnalyticsRequest $request, CurrentOrganization $current, DashboardService $dashboard): DashboardResource
    {
        $this->authorize('viewAny', Transaction::class);

        $period = $request->period();

        return DashboardResource::make($dashboard->kpis($current->organization, $period))
            ->withContext($period, $current->organization);
    }
}
