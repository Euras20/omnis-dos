<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain;

/**
 * One balanced posting line for PostingService.
 */
final class PostingLine
{
    public function __construct(
        public readonly string $accountId,
        public readonly int $debitMinor,
        public readonly int $creditMinor,
        public readonly ?string $partyId = null,
        public readonly ?string $memo = null,
        public readonly ?string $dimension1 = null,
        public readonly ?string $dimension2 = null,
    ) {
        if ($debitMinor < 0 || $creditMinor < 0) {
            throw new \InvalidArgumentException('Debit and credit must be non-negative.');
        }
        if (($debitMinor > 0 && $creditMinor > 0) || ($debitMinor === 0 && $creditMinor === 0)) {
            throw new \InvalidArgumentException('Line must have positive debit XOR credit.');
        }
    }

    public static function debit(
        string $accountId,
        int $amountMinor,
        ?string $partyId = null,
        ?string $memo = null,
    ): self {
        return new self($accountId, $amountMinor, 0, $partyId, $memo);
    }

    public static function credit(
        string $accountId,
        int $amountMinor,
        ?string $partyId = null,
        ?string $memo = null,
    ): self {
        return new self($accountId, 0, $amountMinor, $partyId, $memo);
    }
}
