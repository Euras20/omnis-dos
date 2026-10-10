<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Authenticated but not allowed (permission, portal isolation, location scope, deactivated).
 * HTTP 403. Prefer a specific error code when the frontend needs to distinguish.
 */
final class ForbiddenException extends DomainException
{
    public function __construct(
        string $message = 'You do not have permission to perform this action.',
        string $errorCode = 'forbidden',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, 403, null, $previous);
    }

    protected function defaultErrorCode(): string
    {
        return 'forbidden';
    }

    public static function accountDeactivated(): self
    {
        return new self('This account has been deactivated.', 'account_deactivated');
    }

    public static function locationScope(): self
    {
        return new self('You are not permitted to act on this location.', 'location_scope');
    }

    public static function portalIsolation(): self
    {
        return new self('Portal users may only access their own records.', 'portal_isolation');
    }
}
