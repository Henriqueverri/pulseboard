<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * How clients connect to the realtime server (Pusher protocol, Laravel Reverb). The app key is
 * public by design: it only names the app; subscribing to a private channel still needs
 * /api/v1/broadcasting/auth. Served by the API so the web and mobile builds need no setting.
 */
class RealtimeController extends Controller
{
    public function __invoke(): JsonResponse
    {
        if (config('broadcasting.default') !== 'reverb') {
            return response()->json(['data' => ['enabled' => false]]);
        }

        return response()->json(['data' => [
            'enabled' => true,
            'key' => config('broadcasting.connections.reverb.key'),
            'host' => config('reverb.public.host'),
            'port' => config('reverb.public.port'),
            'scheme' => config('reverb.public.scheme'),
            'auth_endpoint' => '/api/v1/broadcasting/auth',
        ]]);
    }
}
