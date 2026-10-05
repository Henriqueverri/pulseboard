<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlates an API request with its log lines: a well-formed incoming
 * X-Request-Id is kept (so a caller can trace its own id), otherwise a UUID is
 * generated. Registered globally so error responses (404, 429, 500) carry it too.
 */
class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    private const PATTERN = '/\A[A-Za-z0-9._-]{8,64}\z/';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/*')) {
            return $next($request);
        }

        $incoming = $request->headers->get(self::HEADER);
        $requestId = is_string($incoming) && preg_match(self::PATTERN, $incoming) === 1
            ? $incoming
            : (string) Str::uuid();

        $request->headers->set(self::HEADER, $requestId);
        $request->attributes->set('request_id', $requestId);
        Log::shareContext(['request_id' => $requestId]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
