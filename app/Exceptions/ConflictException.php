<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Unique / posting_key / business-key collision or other resolvable state conflict.
 * HTTP 409 conflict.
 */
final class ConflictException extends DomainException
{
    public function __construct(
        string $message = 'The request conflicts with the current state of the resource.',
        string $errorCode = 'conflict',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, 409, null, $previous);
    }

    protected function defaultErrorCode(): string
    {
        return 'conflict';
    }
}
