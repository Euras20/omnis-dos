<?php

declare(strict_types=1);

namespace App\Shared\Idempotency;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces Idempotency-Key on state-changing requests.
 *
 * Applied to command route groups (POST/PUT/PATCH/DELETE under /api/v1).
 * The concrete registration lives with the HTTP middleware stack (Step 14.3);
 * this class is the shared implementation.
 *
 * Flow:
 * 1. Require header (min length from config).
 * 2. Look up key → same hash: replay; different hash: 409.
 * 3. On first request, let the controller run, then store the outcome
 *    (the Action / controller is responsible for calling store() inside
 *    the business transaction for success paths; this middleware stores
 *    only the final response envelope for replay).
 */
final class IdempotencyMiddleware
{
    public function __construct(
        private readonly IdempotencyStore $store,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isStateChanging($request)) {
            return $next($request);
        }

        $key = $request->header(config('omnis.idempotency.header', 'Idempotency-Key'));

        if ($key === null || strlen($key) < (int) config('omnis.idempotency.min_length', 8)) {
            return $this->errorResponse(
                'idempotency_key_required',
                'Idempotency-Key header is required and must be at least 8 characters.',
                400,
                $request
            );
        }

        $requestHash = $this->store->hashRequestBody($request->getContent());
        $existing = $this->store->find($key);

        if ($existing !== null) {
            if ($existing['request_hash'] !== $requestHash) {
                return $this->errorResponse(
                    'idempotency_mismatch',
                    'Idempotency-Key was already used with a different request body.',
                    409,
                    $request
                );
            }

            $response = response()->json(
                $existing['response'],
                $existing['status_code']
            );
            $response->headers->set('Idempotent-Replay', 'true');
            $response->headers->set('x-request-id', $request->attributes->get('request_id', ''));

            return $response;
        }

        /** @var Response $response */
        $response = $next($request);

        // Only cache successful or client-error responses that are safe to replay.
        // 5xx is deliberately not stored so a later retry can succeed.
        if ($response->getStatusCode() < 500 && $response instanceof JsonResponse) {
            $body = $response->getData(true);
            if (is_array($body)) {
                // Store outside the business transaction only for the envelope.
                // Domain Actions that need transactional consistency call
                // IdempotencyStore::store() themselves inside their transaction.
                try {
                    $this->store->store(
                        $key,
                        $requestHash,
                        $response->getStatusCode(),
                        $body
                    );
                } catch (\Throwable) {
                    // Unique violation = concurrent first-writer won; ignore.
                    // The client can retry and will then receive the replay.
                }
            }
        }

        return $response;
    }

    private function isStateChanging(Request $request): bool
    {
        return in_array(strtoupper($request->getMethod()), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    private function errorResponse(string $code, string $message, int $status, Request $request): JsonResponse
    {
        return response()->json([
            'error' => $code,
            'message' => $message,
            'requestId' => $request->attributes->get('request_id'),
        ], $status);
    }
}
