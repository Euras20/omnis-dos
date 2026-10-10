<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Stock or allocation shortfall.
 * HTTP 422 insufficient_quantity.
 */
final class InsufficientQuantityException extends BusinessRuleException
{
    public function __construct(
        string $message = 'Insufficient quantity available.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 'insufficient_quantity', $previous);
    }
}
