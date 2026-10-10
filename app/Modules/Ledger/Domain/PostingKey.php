<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain;

/**
 * Deterministic posting key: source_type:source_id:event
 *
 * Ensures one journal per business event (UNIQUE company_id + posting_key).
 */
final class PostingKey
{
    private function __construct(
        private readonly string $value,
    ) {
    }

    public static function make(string $sourceType, ?string $sourceId, string $event): self
    {
        $id = $sourceId ?? '';

        return new self("{$sourceType}:{$id}:{$event}");
    }

    public static function forManual(string $journalId): self
    {
        return self::make('manual', $journalId, 'post');
    }

    public static function forReverse(string $originalJournalId): self
    {
        return self::make('reverse', $originalJournalId, 'reverse');
    }

    public static function raw(string $value): self
    {
        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
