<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $database = 'ok';

        try {
            DB::connection()->getPdo();
            DB::select('select 1');
        } catch (Throwable) {
            $database = 'error';

            return response()->json([
                'status' => 'degraded',
                'app' => config('app.name'),
                'database' => $database,
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
            'app' => config('app.name'),
            'database' => $database,
        ]);
    }
}
