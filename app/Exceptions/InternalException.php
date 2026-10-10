<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Unexpected failure. Maps to 500 internal_error.
 * Prefer wrapping the original throwable so operators retain the cause in logs.
 */
final class InternalException extends DomainException
{
    public function __construct(
        string $message = 'An unexpected error occurred.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 'internal_error', 500, null, $previous);
    }

    protected function defaultErrorCode(): string
    {
        return 'internal_error';
    }
}
