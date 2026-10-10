<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Allocation or application exceeds the open balance of the document.
 * HTTP 422 open_balance_exceeded.
 */
final class OpenBalanceExceededException extends BusinessRuleException
{
    public function __construct(
        string $message = 'The amount exceeds the open balance of the document.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 'open_balance_exceeded', $previous);
    }
}
