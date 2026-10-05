<?php

use App\Http\Controllers\Api\Analytics\CustomerAnalyticsController;
use App\Http\Controllers\Api\Analytics\ProductAnalyticsController;
use App\Http\Controllers\Api\Analytics\RevenueAnalyticsController;
use App\Http\Controllers\Api\Analytics\TransactionStatusAnalyticsController;
use App\Http\Controllers\Api\ApiKeyController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\Ingest\IngestStatusChangeController;
use App\Http\Controllers\Api\Ingest\IngestTransactionController;
use App\Http\Controllers\Api\Insights\PeriodSummaryController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\OrganizationInsightsController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\TransactionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class);

    Route::prefix('auth')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register'])
            ->middleware('throttle:6,1');
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:6,1');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
        });
    });

    Route::middleware(['auth:sanctum', 'organization'])->group(function (): void {
        Route::get('/organization', [OrganizationController::class, 'show']);
        Route::put('/organization/insights', [OrganizationInsightsController::class, 'update']);
        Route::get('/dashboard', DashboardController::class);

        Route::prefix('analytics')->group(function (): void {
            Route::get('/revenue', RevenueAnalyticsController::class);
            Route::get('/products', ProductAnalyticsController::class);
            Route::get('/customers', CustomerAnalyticsController::class);
            Route::get('/transactions', TransactionStatusAnalyticsController::class);
        });

        // Reading the cached summary is free; only generating it is rate limited.
        Route::prefix('insights')->group(function (): void {
            Route::get('/period-summary', [PeriodSummaryController::class, 'show']);
            Route::post('/period-summary', [PeriodSummaryController::class, 'store'])
                ->middleware('throttle:insights');
        });

        Route::apiResource('products', ProductController::class);
        Route::apiResource('customers', CustomerController::class);
        Route::apiResource('transactions', TransactionController::class)->only(['index', 'show']);

        Route::apiResource('api-keys', ApiKeyController::class)
            ->only(['index', 'store', 'destroy'])
            ->parameters(['api-keys' => 'apiKey']);
    });

    // Integration API: API key only (no session, CSRF or X-Organization-Id), rate limited per key.
    Route::prefix('ingest')->middleware(['api-key', 'throttle:ingest'])->group(function (): void {
        Route::post('/transactions', [IngestTransactionController::class, 'store']);
        Route::get('/transactions/{externalId}', [IngestTransactionController::class, 'show']);
        Route::post('/transactions/{externalId}/status-changes', IngestStatusChangeController::class);
    });
});
