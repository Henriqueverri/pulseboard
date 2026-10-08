<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\IssueTokenRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\UserResource;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public const TOKEN_LIFETIME_DAYS = 30;

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
        /** @var User $user */
        $user = $request->user();

        // A native client has no session to invalidate: logging out revokes its token.
        if ($user->currentAccessToken() instanceof PersonalAccessToken) {
            $user->currentAccessToken()->delete();

            return response()->json([
                'message' => 'Logged out.',
            ]);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Logged out.',
        ]);
    }

    /**
     * Personal access token for a native client, one per device: issuing a token for a
     * device_name replaces the user's previous token for it. No session is started.
     */
    public function issueToken(IssueTokenRequest $request): JsonResponse
    {
        $payload = $request->validated();

        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');

        if (! $guard->validate(['email' => $payload['email'], 'password' => $payload['password']])) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        /** @var User $user */
        $user = $guard->getLastAttempted();

        $token = DB::transaction(function () use ($user, $payload): NewAccessToken {
            // The user's expired tokens go too, since nothing prunes them on a schedule.
            $user->tokens()
                ->where(fn ($query) => $query
                    ->where('name', $payload['device_name'])
                    ->orWhere('expires_at', '<=', now()))
                ->delete();

            return $user->createToken(
                $payload['device_name'],
                ['*'],
                now()->addDays(self::TOKEN_LIFETIME_DAYS),
            );
        });

        return response()
            ->json([
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $token->accessToken->expires_at,
                ...$this->profile($request, $user),
            ], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function revokeCurrentToken(Request $request): JsonResponse|Response
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return response()->json([
                'message' => 'This request is not authenticated with an access token.',
            ], 400);
        }

        $token->delete();

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->profile($request, $user));
    }

    /**
     * @return array{user: UserResource, organizations: AnonymousResourceCollection, current_organization: ?OrganizationResource}
     */
    private function profile(Request $request, User $user): array
    {
        $organizations = $user->organizations()->orderBy('name')->get();

        $current = null;
        $headerId = $request->header('X-Organization-Id');

        if (is_string($headerId) && Str::isUuid($headerId)) {
            $current = $organizations->firstWhere('id', $headerId);
        }

        $current ??= $organizations->first();

        return [
            'user' => new UserResource($user),
            'organizations' => OrganizationResource::collection($organizations),
            'current_organization' => $current
                ? new OrganizationResource($current)
                : null,
        ];
    }
}
