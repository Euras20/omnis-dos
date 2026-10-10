<?php

declare(strict_types=1);

use App\Exceptions\DomainException;
use App\Exceptions\ValidationException as OmnisValidationException;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\EnsureRequestId;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\SetCompanyContext;
use App\Shared\Idempotency\IdempotencyMiddleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException as LaravelValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;


return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'omnis.request_id' => EnsureRequestId::class,
            'omnis.auth' => Authenticate::class,
            'omnis.company' => SetCompanyContext::class,
            'omnis.idempotency' => IdempotencyMiddleware::class,
            'omnis.json' => ForceJsonResponse::class,
        ]);

        // Applied to every route registered under routes/api.php (prefix api/).
        $middleware->api(prepend: [
            ForceJsonResponse::class,
            EnsureRequestId::class,
        ]);

        // Authenticated business stack is applied per route group in routes/api.php
        // via middleware(['omnis.auth', 'omnis.company', 'omnis.idempotency']).
        // Order matters: request id → auth → company → idempotency.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Canonical OMNIS domain exceptions → error shape.
        $exceptions->render(function (DomainException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $payload = $e->toErrorArray();
            $payload['requestId'] = $request->attributes->get('request_id')
                ?? $request->header('X-Request-Id');

            return response()->json($payload, $e->status());
        });

        // Laravel Form Request validation → same shape.
        $exceptions->render(function (LaravelValidationException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $fields = $e->errors();
            $omnis = OmnisValidationException::fromFields($fields, $e->getMessage());

            $payload = $omnis->toErrorArray();
            $payload['requestId'] = $request->attributes->get('request_id')
                ?? $request->header('X-Request-Id');

            return response()->json($payload, 400);
        });

        // Unauthenticated (framework) → 401.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'error' => 'unauthenticated',
                'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Authentication required.',
                'requestId' => $request->attributes->get('request_id')
                    ?? $request->header('X-Request-Id'),
            ], 401);
        });

        // Other HTTP exceptions keep their status but use the canonical body.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $status = $e->getStatusCode();
            $code = match ($status) {
                401 => 'unauthenticated',
                403 => 'forbidden',
                404 => 'not_found',
                409 => 'conflict',
                422 => 'business_rule',
                429 => 'rate_limited',
                default => $status >= 500 ? 'internal_error' : 'http_error',
            };

            return response()->json([
                'error' => $code,
                'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Request failed.',
                'requestId' => $request->attributes->get('request_id')
                    ?? $request->header('X-Request-Id'),
            ], $status);
        });

        // Unexpected exceptions → 500 internal_error (never leak stack traces).
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            report($e);

            return response()->json([
                'error' => 'internal_error',
                'message' => 'An unexpected error occurred.',
                'requestId' => $request->attributes->get('request_id')
                    ?? $request->header('X-Request-Id'),
            ], 500);
        });
    })->create();
