<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Missing or invalid identity token / principal.
 * HTTP 401 unauthenticated.
 */
final class UnauthenticatedException extends DomainException
{
    public function __construct(
        string $message = 'Authentication required.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 'unauthenticated', 401, null, $previous);
    }

    protected function defaultErrorCode(): string
    {
        return 'unauthenticated';
    }
}
