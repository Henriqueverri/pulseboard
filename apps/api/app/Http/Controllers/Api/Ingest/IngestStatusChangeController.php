<?php

namespace App\Http\Controllers\Api\Ingest;

use App\Exceptions\IngestionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ingest\StoreStatusChangeRequest;
use App\Http\Resources\IngestedTransactionResource;
use App\Models\ApiKey;
use App\Services\TransactionLifecycle;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A status transition is modelled as creating a status change resource, not as
 * patching a field. Which transitions are allowed is decided by the lifecycle.
 */
class IngestStatusChangeController extends Controller
{
    public function __invoke(
        StoreStatusChangeRequest $request,
        string $externalId,
        CurrentOrganization $current,
        TransactionLifecycle $lifecycle,
    ): JsonResponse {
        $startedAt = microtime(true);
        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('api_key');
        $status = $request->status();

        try {
            $result = $lifecycle->transition(
                $current->organization,
                $apiKey,
                $externalId,
                $status,
                $request->occurredAt(),
            );
        } catch (IngestionException $exception) {
            $this->logOutcome(
                $apiKey,
                $externalId,
                $status->value,
                match ($exception->httpStatus) {
                    Response::HTTP_CONFLICT => 'conflict',
                    Response::HTTP_NOT_FOUND => 'not_found',
                    default => 'rejected',
                },
                $exception->httpStatus,
                $exception->errorCode,
                $startedAt,
            );

            throw $exception;
        } catch (Throwable $exception) {
            $this->logOutcome(
                $apiKey,
                $externalId,
                $status->value,
                'failed',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                'internal_error',
                $startedAt,
            );

            throw $exception;
        }

        $httpStatus = $result->applied ? Response::HTTP_CREATED : Response::HTTP_OK;

        $this->logOutcome(
            $apiKey,
            $externalId,
            $status->value,
            $result->applied ? 'transitioned' : 'replayed',
            $httpStatus,
            null,
            $startedAt,
            $result->transaction->id,
        );

        return IngestedTransactionResource::make($result->transaction)
            ->response()
            ->setStatusCode($httpStatus)
            ->withHeaders($result->applied ? [] : ['Idempotent-Replayed' => 'true']);
    }

    private function logOutcome(
        ApiKey $apiKey,
        string $externalId,
        string $requestedStatus,
        string $outcome,
        int $httpStatus,
        ?string $code,
        float $startedAt,
        ?string $transactionId = null,
    ): void {
        Log::info('Transaction status change completed.', [
            'event' => 'ingest.status_change',
            'outcome' => $outcome,
            'organization_id' => $apiKey->organization_id,
            'api_key_id' => $apiKey->id,
            'api_key_prefix' => $apiKey->prefix,
            'external_id' => $externalId,
            'transaction_id' => $transactionId,
            'requested_status' => $requestedStatus,
            'http_status' => $httpStatus,
            'code' => $code,
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
        ]);
    }
}
