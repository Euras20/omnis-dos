<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures API clients always receive JSON error bodies by setting the
 * Accept header when missing. Does not change successful responses.
 */
final class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->headers->has('Accept') || $request->headers->get('Accept') === '*/*') {
            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }
}
