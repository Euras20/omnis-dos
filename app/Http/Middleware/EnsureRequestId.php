<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Shared\Support\RequestId;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures every request has a correlation request ID.
 *
 * - Accepts client-supplied X-Request-Id when it is a valid UUID.
 * - Otherwise generates a new UUID.
 * - Stores it on the request attributes and echoes it on the response.
 *
 * Order: first in the /api/v1 stack (06-authn-authz.md §6.5).
 */
final class EnsureRequestId
{
    public const HEADER = 'X-Request-Id';

    public const ATTRIBUTE = 'request_id';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = RequestId::fromHeader($request->header(self::HEADER));

        $request->attributes->set(self::ATTRIBUTE, $requestId);
        $request->headers->set(self::HEADER, $requestId);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
