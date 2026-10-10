<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ForbiddenException;
use App\Exceptions\UnauthenticatedException;
use App\Models\AppUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the authenticated principal for /api/v1.
 *
 * Drivers (config omnis.auth.driver):
 * - cloudflare — Cf-Access-Jwt-Assertion (production preferred)
 * - entra     — Authorization: Bearer (Entra access token)
 * - dev       — X-Dev-User email header (local / testing only)
 *
 * JWT signature validation is intentionally thin in this phase: production
 * deployments sit behind Cloudflare Access or Entra which already validated
 * the token. Full JWKS verification can be added without changing the
 * resolution contract.
 *
 * Lookup is defensive until Identity migrations exist (14.4 / 14.5).
 *
 * Order: after EnsureRequestId (06-authn-authz.md §6.5).
 */
final class Authenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        $driver = (string) config('omnis.auth.driver', 'cloudflare');

        $user = match ($driver) {
            'dev' => $this->resolveDev($request),
            'entra' => $this->resolveEntra($request),
            default => $this->resolveCloudflare($request),
        };

        if ($user === null) {
            throw new UnauthenticatedException('Authentication required.');
        }

        if (! $user->is_active) {
            throw ForbiddenException::accountDeactivated();
        }

        // Attach for controllers / Form Requests / policies.
        $request->setUserResolver(static fn () => $user);
        $request->attributes->set('app_user', $user);

        return $next($request);
    }

    private function resolveDev(Request $request): ?AppUser
    {
        $env = app()->environment();
        if (! in_array($env, ['local', 'testing'], true)) {
            // Fail closed outside local/testing.
            throw new UnauthenticatedException('Development authentication is not allowed in this environment.');
        }

        $header = (string) config('omnis.auth.dev_header', 'X-Dev-User');
        $email = $request->header($header);

        if ($email === null || $email === '') {
            throw new UnauthenticatedException("Missing {$header} header for development authentication.");
        }

        return $this->findUserByEmail(strtolower(trim($email)));
    }

    private function resolveCloudflare(Request $request): ?AppUser
    {
        $header = (string) config('omnis.auth.cloudflare_header', 'Cf-Access-Jwt-Assertion');
        $token = $request->header($header);

        if ($token === null || $token === '') {
            // Fall back to Bearer if present (some edge setups).
            $token = $this->bearerToken($request);
        }

        if ($token === null || $token === '') {
            return null;
        }

        $claims = $this->decodeJwtPayload($token);
        if ($claims === null) {
            throw new UnauthenticatedException('Invalid identity token.');
        }

        $email = isset($claims['email']) ? strtolower((string) $claims['email']) : null;
        $subject = isset($claims['sub']) ? (string) $claims['sub'] : null;

        if ($subject !== null) {
            $user = $this->findUserByExternalSubject($subject);
            if ($user !== null) {
                return $user;
            }
        }

        if ($email !== null && $email !== '') {
            return $this->findUserByEmail($email);
        }

        return null;
    }

    private function resolveEntra(Request $request): ?AppUser
    {
        $token = $this->bearerToken($request);
        if ($token === null || $token === '') {
            return null;
        }

        $claims = $this->decodeJwtPayload($token);
        if ($claims === null) {
            throw new UnauthenticatedException('Invalid identity token.');
        }

        // Prefer oid (Entra object id) then sub, then email / preferred_username.
        $subject = isset($claims['oid'])
            ? (string) $claims['oid']
            : (isset($claims['sub']) ? (string) $claims['sub'] : null);

        $email = null;
        foreach (['email', 'preferred_username', 'upn'] as $key) {
            if (! empty($claims[$key])) {
                $email = strtolower((string) $claims[$key]);
                break;
            }
        }

        if ($subject !== null) {
            $user = $this->findUserByExternalSubject($subject);
            if ($user !== null) {
                return $user;
            }
        }

        if ($email !== null) {
            return $this->findUserByEmail($email);
        }

        return null;
    }

    private function bearerToken(Request $request): ?string
    {
        $header = $request->header('Authorization');
        if ($header === null || ! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token === '' ? null : $token;
    }

    /**
     * Decode JWT payload without signature verification.
     * Signature is assumed already verified by the edge IdP in production.
     *
     * @return array<string, mixed>|null
     */
    private function decodeJwtPayload(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            return null;
        }

        $payload = $parts[1];
        $remainder = strlen($payload) % 4;
        if ($remainder > 0) {
            $payload .= str_repeat('=', 4 - $remainder);
        }

        $json = base64_decode(strtr($payload, '-_', '+/'), true);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    private function findUserByEmail(string $email): ?AppUser
    {
        try {
            $row = DB::table('app_user')
                ->whereRaw('lower(email) = ?', [$email])
                ->first();
        } catch (\Throwable) {
            // Table may not exist yet (pre-14.4). Treat as unauthenticated.
            return null;
        }

        return $row === null ? null : AppUser::fromRow($row);
    }

    private function findUserByExternalSubject(string $subject): ?AppUser
    {
        try {
            $row = DB::table('app_user')
                ->where('external_subject', $subject)
                ->first();
        } catch (\Throwable) {
            return null;
        }

        return $row === null ? null : AppUser::fromRow($row);
    }
}
