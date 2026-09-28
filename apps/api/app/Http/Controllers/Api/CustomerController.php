<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\IndexCustomerRequest;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\CustomerMetricsService;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CustomerController extends Controller
{
    /**
     * Sorted by name, then id, so pagination is stable.
     */
    public function index(IndexCustomerRequest $request, CurrentOrganization $current): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Customer::class);

        $customers = $current->organization->customers()
            ->when($request->validated('q'), fn ($query, string $term) => $query->search($term))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return CustomerResource::collection($customers);
    }

    public function store(StoreCustomerRequest $request, CurrentOrganization $current): JsonResponse
    {
        $this->authorize('create', Customer::class);

        $customer = $current->organization->customers()->create($request->validated());

        return CustomerResource::make($customer->refresh())
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Customer $customer, CustomerMetricsService $metrics): CustomerResource
    {
        $this->authorize('view', $customer);

        return CustomerResource::make($customer)->withMetrics($metrics->for($customer));
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): CustomerResource
    {
        $this->authorize('update', $customer);

        $customer->update($request->validated());

        return CustomerResource::make($customer);
    }

    public function destroy(Customer $customer): Response
    {
        $this->authorize('delete', $customer);

        $customer->deletePreservingHistory();

        return response()->noContent();
    }
}
