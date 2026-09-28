<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\IndexProductRequest;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\ProductMetricsService;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProductController extends Controller
{
    /**
     * Sorted by name, then id, so pagination is stable.
     */
    public function index(IndexProductRequest $request, CurrentOrganization $current): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Product::class);

        $products = $current->organization->products()
            ->when($request->validated('status'), fn ($query, string $status) => $query->where('status', $status))
            ->when($request->validated('q'), fn ($query, string $term) => $query->search($term))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return ProductResource::collection($products);
    }

    public function store(StoreProductRequest $request, CurrentOrganization $current): JsonResponse
    {
        $this->authorize('create', Product::class);

        $product = $current->organization->products()->create($request->validated());

        return ProductResource::make($product->refresh())
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Product $product, ProductMetricsService $metrics): ProductResource
    {
        $this->authorize('view', $product);

        return ProductResource::make($product)->withMetrics($metrics->for($product));
    }

    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        $this->authorize('update', $product);

        $product->update($request->validated());

        return ProductResource::make($product);
    }

    public function destroy(Product $product): Response
    {
        $this->authorize('delete', $product);

        $product->deletePreservingHistory();

        return response()->noContent();
    }
}
