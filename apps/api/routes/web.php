<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'app' => config('app.name'),
    'api' => url('/api/v1'),
    'health' => url('/api/v1/health'),
]))->withoutMiddleware('web');
