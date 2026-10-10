<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\UnauthenticatedException;
use App\Models\AppUser;
use App\Shared\Company\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Injects company_id / user_id / request_id into the shared CompanyContext
 * from the authenticated principal. Clients never supply company_id on
 * ordinary business requests.
 *
 * Order: after Authenticate (06-authn-authz.md §6.5).
 */
final class SetCompanyContext
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        /** @var AppUser|null $user */
        $user = $request->user();

        if ($user === null || ! $user instanceof AppUser) {
            throw new UnauthenticatedException('Authentication required.');
        }

        $companyId = (string) $user->company_id;
        if ($companyId === '') {
            throw new UnauthenticatedException('Authenticated user has no company context.');
        }

        $requestId = $request->attributes->get(EnsureRequestId::ATTRIBUTE);

        $this->companyContext->set(
            $companyId,
            (string) $user->id,
            is_string($requestId) ? $requestId : null,
        );

        return $next($request);
    }
}
