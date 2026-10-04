<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApiKey\StoreApiKeyRequest;
use App\Http\Resources\ApiKeyResource;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Support\ApiKeyGenerator;
use App\Support\CurrentOrganization;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Internal management of the organization's integration keys. Keys are revoked,
 * never deleted, so the transactions they ingested keep pointing to them.
 */
class ApiKeyController extends Controller
{
    /**
     * Keys in the public demo organization always expire within this many hours,
     * whatever expiration was requested.
     */
    public const DEMO_EXPIRATION_HOURS = 24;

    /**
     * Newest first, revoked and expired keys included (they stay listed for auditing).
     */
    public function index(CurrentOrganization $current): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ApiKey::class);

        $apiKeys = $current->organization->apiKeys()
            ->with('createdBy:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return ApiKeyResource::collection($apiKeys);
    }

    /**
     * The only response that ever contains the secret (plain_text_key).
     */
    public function store(StoreApiKeyRequest $request, CurrentOrganization $current, ApiKeyGenerator $generator): JsonResponse
    {
        $this->authorize('create', ApiKey::class);

        $organization = $current->organization;
        $generated = $generator->generate();

        $apiKey = DB::transaction(function () use ($request, $organization, $generated): ApiKey {
            // Serializes concurrent creations so the active-key limit holds.
            Organization::query()->whereKey($organization->id)->lockForUpdate()->first();

            if ($organization->apiKeys()->active()->count() >= ApiKey::MAX_ACTIVE_PER_ORGANIZATION) {
                throw ValidationException::withMessages([
                    'api_keys' => 'This organization already has '.ApiKey::MAX_ACTIVE_PER_ORGANIZATION.' active API keys. Revoke one before creating another.',
                ]);
            }

            $apiKey = new ApiKey(['name' => $request->validated('name')]);
            $apiKey->forceFill([
                'organization_id' => $organization->id,
                'created_by_user_id' => $request->user()->id,
                'prefix' => $generated['prefix'],
                'secret_hash' => $generated['secret_hash'],
                'expires_at' => $this->expirationFor($organization, $request->expiresInDays()),
            ])->save();

            return $apiKey;
        });

        return ApiKeyResource::make($apiKey->refresh()->load('createdBy:id,name'))
            ->withPlainTextKey($generated['plain_text_key'])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED)
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Revokes the key (idempotent); the row is kept for auditing.
     */
    public function destroy(ApiKey $apiKey): Response
    {
        $this->authorize('delete', $apiKey);

        $apiKey->revoke();

        return response()->noContent();
    }

    private function expirationFor(Organization $organization, ?int $days): ?CarbonInterface
    {
        if ($organization->isDemo()) {
            return now()->addHours(self::DEMO_EXPIRATION_HOURS);
        }

        return $days === null ? null : now()->addDays($days);
    }
}
