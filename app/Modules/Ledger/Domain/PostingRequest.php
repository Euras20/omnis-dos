<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain;

/**
 * Request to create and post a journal via Ledger\PostingService.
 *
 * Called by other modules (Receivables, Payables, Inventory, …) and by
 * manual journal post. Must run inside the caller's DB transaction.
 *
 * @phpstan-type LineArray array{
 *     accountId: string,
 *     debitMinor?: int,
 *     creditMinor?: int,
 *     partyId?: string|null,
 *     memo?: string|null,
 *     dimension1?: string|null,
 *     dimension2?: string|null
 * }
 */
final class PostingRequest
{
    /**
     * @param  list<PostingLine>  $lines
     */
    public function __construct(
        public readonly string $companyId,
        public readonly string $postingDate,
        public readonly PostingKey $postingKey,
        public readonly string $sourceType,
        public readonly ?string $sourceId,
        public readonly ?string $eventType,
        public readonly string $description,
        public readonly array $lines,
        public readonly string $actorId,
        public readonly ?string $requestId = null,
        public readonly string $currency = 'GHS',
        public readonly ?string $preparedBy = null,
        public readonly bool $isManual = false,
    ) {
        if ($lines === []) {
            throw new \InvalidArgumentException('PostingRequest requires at least one line.');
        }
    }
}
