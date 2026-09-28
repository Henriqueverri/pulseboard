<?php

use App\Http\Controllers\Api\Analytics\CustomerAnalyticsController;
use App\Http\Controllers\Api\Analytics\ProductAnalyticsController;
use App\Http\Controllers\Api\Analytics\RevenueAnalyticsController;
use App\Http\Controllers\Api\Analytics\TransactionStatusAnalyticsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\OrganizationController;
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
        Route::get('/dashboard', DashboardController::class);

        Route::prefix('analytics')->group(function (): void {
            Route::get('/revenue', RevenueAnalyticsController::class);
            Route::get('/products', ProductAnalyticsController::class);
            Route::get('/customers', CustomerAnalyticsController::class);
            Route::get('/transactions', TransactionStatusAnalyticsController::class);
        });

        Route::apiResource('products', ProductController::class);
        Route::apiResource('customers', CustomerController::class);
        Route::apiResource('transactions', TransactionController::class)->only(['index', 'show']);
    });
});
