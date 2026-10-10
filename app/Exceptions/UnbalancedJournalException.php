<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Journal debits do not equal credits (pre-check or deferred constraint).
 * HTTP 422 unbalanced_journal.
 */
final class UnbalancedJournalException extends BusinessRuleException
{
    public function __construct(
        string $message = 'Journal is unbalanced: total debits must equal total credits.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 'unbalanced_journal', $previous);
    }

    public static function withTotals(int $debitMinor, int $creditMinor): self
    {
        return new self(
            "Journal is unbalanced: debits {$debitMinor} do not equal credits {$creditMinor}."
        );
    }
}
