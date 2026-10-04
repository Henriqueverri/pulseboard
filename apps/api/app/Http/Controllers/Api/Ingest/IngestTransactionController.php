<?php

namespace App\Http\Controllers\Api\Ingest;

use App\Exceptions\IngestionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ingest\IngestTransactionRequest;
use App\Http\Resources\IngestedTransactionResource;
use App\Models\ApiKey;
use App\Services\Ingestion\TransactionIngestionService;
use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Integration API, authenticated by API key: there is no user, so there are no
 * policies. Every lookup starts from the key's organization.
 */
class IngestTransactionController extends Controller
{
    public function store(
        IngestTransactionRequest $request,
        CurrentOrganization $current,
        TransactionIngestionService $service,
    ): JsonResponse {
        $startedAt = microtime(true);
        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('api_key');
        $data = $request->transactionData();

        try {
            $result = $service->ingest($current->organization, $apiKey, $data);
        } catch (IngestionException $exception) {
            $this->logOutcome(
                $apiKey,
                $data->externalId,
                count($data->items),
                $exception->httpStatus === Response::HTTP_CONFLICT ? 'conflict' : 'rejected',
                $exception->httpStatus,
                $exception->errorCode,
                $startedAt,
            );

            throw $exception;
        } catch (Throwable $exception) {
            $this->logOutcome(
                $apiKey,
                $data->externalId,
                count($data->items),
                'failed',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                'internal_error',
                $startedAt,
            );

            throw $exception;
        }

        $status = $result->replayed ? Response::HTTP_OK : Response::HTTP_CREATED;
        $headers = $result->replayed
            ? ['Idempotent-Replayed' => 'true']
            : ['Location' => '/api/v1/ingest/transactions/'.rawurlencode($data->externalId)];

        $this->logOutcome(
            $apiKey,
            $data->externalId,
            count($data->items),
            $result->replayed ? 'replayed' : 'created',
            $status,
            null,
            $startedAt,
            $result->transaction->id,
        );

        return IngestedTransactionResource::make($result->transaction)
            ->response()
            ->setStatusCode($status)
            ->withHeaders($headers);
    }

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

    private function logOutcome(
        ApiKey $apiKey,
        string $externalId,
        int $itemsCount,
        string $outcome,
        int $httpStatus,
        ?string $code,
        float $startedAt,
        ?string $transactionId = null,
    ): void {
        Log::info('Transaction ingestion completed.', [
            'event' => 'ingest.transaction',
            'outcome' => $outcome,
            'organization_id' => $apiKey->organization_id,
            'api_key_id' => $apiKey->id,
            'api_key_prefix' => $apiKey->prefix,
            'external_id' => $externalId,
            'transaction_id' => $transactionId,
            'items_count' => $itemsCount,
            'http_status' => $httpStatus,
            'code' => $code,
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
        ]);
    }
}
