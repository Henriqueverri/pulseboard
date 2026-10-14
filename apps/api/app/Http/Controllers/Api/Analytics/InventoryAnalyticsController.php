<?php

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Controllers\Controller;
use App\Http\Resources\Analytics\InventoryAnalyticsResource;
use App\Models\Product;
use App\Services\Analytics\InventoryAnalyticsService;
use App\Support\CurrentOrganization;

class InventoryAnalyticsController extends Controller
{
    public function __invoke(CurrentOrganization $current, InventoryAnalyticsService $inventory): InventoryAnalyticsResource
    {
        $this->authorize('viewAny', Product::class);

        return InventoryAnalyticsResource::make($inventory->report($current->organization));
    }
}
