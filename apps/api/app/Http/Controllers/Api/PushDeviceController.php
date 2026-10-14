<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Devices\PushTokenRequest;
use App\Models\PushDevice;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Push tokens of the mobile app. Only a personal access token can register one: the device is
 * tied to that token, so revoking it (logout, expiry, a new login) also removes the device.
 */
class PushDeviceController extends Controller
{
    /**
     * Idempotent: registering again refreshes last_seen_at. A token registered by another user
     * (someone else signed in on this phone) moves to the current user.
     */
    public function store(PushTokenRequest $request): JsonResponse|Response
    {
        /** @var User $user */
        $user = $request->user();
        $accessToken = $user->currentAccessToken();

        if (! $accessToken instanceof PersonalAccessToken) {
            return response()->json([
                'message' => 'Push notifications require an access token (mobile app).',
            ], 400);
        }

        PushDevice::query()->updateOrCreate(
            ['token' => $request->validated('token')],
            [
                'user_id' => $user->id,
                'personal_access_token_id' => $accessToken->id,
                'platform' => $request->validated('platform'),
                'last_seen_at' => now(),
            ],
        );

        return response()->noContent();
    }

    /**
     * Turning the alerts off on the device. Idempotent, and limited to the user's own devices.
     */
    public function destroy(PushTokenRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $user->pushDevices()->where('token', $request->validated('token'))->delete();

        return response()->noContent();
    }
}
