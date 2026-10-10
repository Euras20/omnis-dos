<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Same Idempotency-Key used with a different request body hash.
 * HTTP 409 idempotency_mismatch.
 */
final class IdempotencyMismatchException extends DomainException
{
    public function __construct(
        string $message = 'Idempotency-Key was already used with a different request body.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 'idempotency_mismatch', 409, null, $previous);
    }

    protected function defaultErrorCode(): string
    {
        return 'idempotency_mismatch';
    }
}
