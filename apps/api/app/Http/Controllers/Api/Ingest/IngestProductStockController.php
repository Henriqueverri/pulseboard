<?php

namespace App\Http\Controllers\Api\Ingest;

use App\Exceptions\IngestionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ingest\PutProductStockRequest;
use App\Http\Resources\IngestedProductStockResource;
use App\Models\ApiKey;
use App\Services\Ingestion\ProductStockIngestionService;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A PUT of the absolute stock: repeating it, or receiving it after a newer one,
 * leaves the stored stock unchanged and answers 200 with Idempotent-Replayed.
 */
class IngestProductStockController extends Controller
{
    public function __invoke(
        PutProductStockRequest $request,
        string $sku,
        CurrentOrganization $current,
        ProductStockIngestionService $service,
    ): JsonResponse {
        $startedAt = microtime(true);
        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('api_key');
        $data = $request->stockData();

        try {
            $result = $service->update($current->organization, $sku, $data);
        } catch (IngestionException $exception) {
            $this->logOutcome($apiKey, $sku, 'rejected', $exception->httpStatus, $exception->errorCode, $startedAt);

            throw $exception;
        } catch (Throwable $exception) {
            $this->logOutcome($apiKey, $sku, 'failed', Response::HTTP_INTERNAL_SERVER_ERROR, 'internal_error', $startedAt);

            throw $exception;
        }

        $this->logOutcome(
            $apiKey,
            $sku,
            $result->applied ? 'updated' : 'replayed',
            Response::HTTP_OK,
            null,
            $startedAt,
            $result->product->id,
            $data->quantity,
        );

        return IngestedProductStockResource::make($result->product)
            ->response()
            ->withHeaders($result->applied ? [] : ['Idempotent-Replayed' => 'true']);
    }

    private function logOutcome(
        ApiKey $apiKey,
        string $sku,
        string $outcome,
        int $httpStatus,
        ?string $code,
        float $startedAt,
        ?string $productId = null,
        ?int $quantity = null,
    ): void {
        Log::info('Product stock update completed.', [
            'event' => 'ingest.product_stock',
            'outcome' => $outcome,
            'organization_id' => $apiKey->organization_id,
            'api_key_id' => $apiKey->id,
            'api_key_prefix' => $apiKey->prefix,
            'sku' => $sku,
            'product_id' => $productId,
            'quantity' => $quantity,
            'http_status' => $httpStatus,
            'code' => $code,
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
        ]);
    }
}
