<?php

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\ProductAnalyticsRequest;
use App\Http\Resources\Analytics\ProductAnalyticsResource;
use App\Models\Transaction;
use App\Services\Analytics\ProductAnalyticsService;
use App\Support\CurrentOrganization;

class ProductAnalyticsController extends Controller
{
    public function __invoke(ProductAnalyticsRequest $request, CurrentOrganization $current, ProductAnalyticsService $products): ProductAnalyticsResource
    {
        $this->authorize('viewAny', Transaction::class);

        $period = $request->period();
        $sort = $request->sort();
        $limit = $request->limit();

        return ProductAnalyticsResource::make($products->report($current->organization, $period, $sort, $limit))
            ->withContext($period, $current->organization)
            ->withRanking($sort, $limit);
    }
}
