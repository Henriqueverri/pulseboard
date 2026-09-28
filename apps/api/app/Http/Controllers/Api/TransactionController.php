<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Transaction\IndexTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only: transactions are historical records and are not writable through the API.
 */
class TransactionController extends Controller
{
    /**
     * Sorted by occurred_at desc, then id desc, so pagination is stable.
     * Items are counted, not loaded; use show() for line items.
     */
    public function index(IndexTransactionRequest $request, CurrentOrganization $current): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Transaction::class);

        $transactions = $current->organization->transactions()
            ->with('customer:id,name,email,deleted_at')
            ->withCount('items')
            ->when($request->validated('status'), fn ($query, string $status) => $query->where('status', $status))
            ->when($request->validated('customer_id'), fn ($query, string $id) => $query->where('customer_id', $id))
            ->when($request->validated('q'), fn ($query, string $term) => $query->search($term))
            ->when($request->occurredFrom(), fn ($query, $from) => $query->where('occurred_at', '>=', $from))
            ->when($request->occurredBefore(), fn ($query, $before) => $query->where('occurred_at', '<', $before))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return TransactionResource::collection($transactions);
    }

    public function show(Transaction $transaction): TransactionResource
    {
        $this->authorize('view', $transaction);

        $transaction->load([
            'customer',
            'items' => fn (HasMany $items) => $items->orderBy('created_at')->orderBy('id'),
            'items.product',
        ]);

        return TransactionResource::make($transaction);
    }
}
