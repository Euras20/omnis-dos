<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Same-actor segregation-of-duties check failed.
 * HTTP 403 sod_violation (frontend treats as blocked action).
 */
final class SodViolationException extends DomainException
{
    public function __construct(
        string $message = 'Segregation of duties: the same person may not perform both steps.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 'sod_violation', 403, null, $previous);
    }

    protected function defaultErrorCode(): string
    {
        return 'sod_violation';
    }
}
