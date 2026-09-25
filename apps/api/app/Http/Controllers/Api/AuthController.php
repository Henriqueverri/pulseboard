<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\UserResource;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $payload = $request->validated();

        $user = DB::transaction(function () use ($payload): User {
            $user = User::query()->create([
                'name' => $payload['name'],
                'email' => $payload['email'],
                'password' => $payload['password'],
            ]);

            $organizationName = $payload['name']."'s Organization";

            $organization = Organization::query()->create([
                'name' => $organizationName,
                'slug' => Organization::uniqueSlugFrom($organizationName),
                'currency' => 'BRL',
            ]);

            $organization->users()->attach($user->id, [
                'role' => Organization::ROLE_OWNER,
            ]);

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        $organization = $user->organizations()->first();

        return response()->json([
            'user' => new UserResource($user),
            'organization' => new OrganizationResource($organization),
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = $request->user();
        $organization = $user->organizations()->first();

        return response()->json([
            'user' => new UserResource($user),
            'organization' => $organization
                ? new OrganizationResource($organization)
                : null,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Logged out.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $organizations = $user->organizations()->orderBy('name')->get();

        $current = null;
        $headerId = $request->header('X-Organization-Id');

        if (is_string($headerId) && Str::isUuid($headerId)) {
            $current = $organizations->firstWhere('id', $headerId);
        }

        $current ??= $organizations->first();

        return response()->json([
            'user' => new UserResource($user),
            'organizations' => OrganizationResource::collection($organizations),
            'current_organization' => $current
                ? new OrganizationResource($current)
                : null,
        ]);
    }
}
