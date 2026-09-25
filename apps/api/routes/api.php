<?php

use App\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class);
});
