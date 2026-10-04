<?php

namespace App\Http\Controllers\Api\Ingest;

use App\Http\Controllers\Controller;
use App\Http\Resources\IngestedTransactionResource;
use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration API, authenticated by API key: there is no user, so there are no
 * policies. Every lookup starts from the key's organization.
 */
class IngestTransactionController extends Controller
{
    /**
     * Reconciliation: the answer to "my request timed out, was it processed?".
     * A transaction of another organization is indistinguishable from a missing one.
     */
    public function show(string $externalId, CurrentOrganization $current): JsonResponse|IngestedTransactionResource
    {
        $transaction = $current->organization->transactions()
            ->where('external_id', $externalId)
            ->with([
                'customer',
                'items' => fn (HasMany $items) => $items->orderBy('created_at')->orderBy('id'),
                'items.product',
                'statusChanges',
            ])
            ->first();

        if ($transaction === null) {
            return response()->json([
                'message' => 'Transaction not found.',
                'code' => 'not_found',
            ], Response::HTTP_NOT_FOUND);
        }

        $transaction->setRelation('organization', $current->organization);

        return IngestedTransactionResource::make($transaction);
    }
}
