<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Optimistic concurrency: client rowVersion does not match current revision.
 * HTTP 409 stale_revision.
 */
final class StaleRevisionException extends DomainException
{
    public function __construct(
        string $message = 'The resource was modified by another request. Refresh and try again.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 'stale_revision', 409, null, $previous);
    }

    protected function defaultErrorCode(): string
    {
        return 'stale_revision';
    }
}
