<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Support\ApiKeyGenerator;
use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates integration requests by API key only: no session, cookie, CSRF
 * or X-Organization-Id. The tenant comes exclusively from the key, and is bound
 * as the same CurrentOrganization the internal API uses.
 *
 * Every failure is the same generic 401; the real reason only goes to the log.
 */
class AuthenticateApiKey
{
    public function __construct(private readonly ApiKeyGenerator $generator) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            return $this->reject('missing');
        }

        $parsed = $this->generator->parse($token);

        if ($parsed === null) {
            return $this->reject('malformed');
        }

        $apiKey = ApiKey::query()->with('organization')->where('prefix', $parsed['prefix'])->first();

        if ($apiKey === null) {
            return $this->reject('unknown', $parsed['prefix']);
        }

        if (! $this->generator->matches($apiKey, $parsed['secret'])) {
            return $this->reject('wrong_secret', $apiKey->prefix);
        }

        if ($apiKey->isRevoked()) {
            return $this->reject('revoked', $apiKey->prefix);
        }

        if ($apiKey->isExpired()) {
            return $this->reject('expired', $apiKey->prefix);
        }

        $apiKey->recordUsage();

        app()->instance(CurrentOrganization::class, new CurrentOrganization($apiKey->organization));
        $request->attributes->set('organization', $apiKey->organization);
        $request->attributes->set('api_key', $apiKey);

        return $next($request);
    }

    /**
     * Never logs the token, the secret or its hash: at most the public prefix.
     */
    private function reject(string $reason, ?string $prefix = null): JsonResponse
    {
        Log::warning('API key authentication failed.', [
            'event' => 'ingest.auth_failed',
            'reason' => $reason,
            'api_key_prefix' => $prefix,
        ]);

        return response()->json([
            'message' => 'Invalid API key.',
            'code' => 'invalid_api_key',
        ], Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => 'Bearer']);
    }
}
