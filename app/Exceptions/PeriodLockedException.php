<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Accounting period is locked for the business date.
 * HTTP 422 period_locked.
 */
final class PeriodLockedException extends DomainException
{
    public function __construct(
        string $message = 'The accounting period is locked for this date.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 'period_locked', 422, null, $previous);
    }

    protected function defaultErrorCode(): string
    {
        return 'period_locked';
    }

    public static function forDate(string $businessDate): self
    {
        return new self("The accounting period for {$businessDate} is locked.");
    }
}
