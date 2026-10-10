<?php

declare(strict_types=1);

namespace App\Shared\Outbox;

/**
 * Value object describing a single transactional outbox row.
 * Written by OutboxWriter inside the business transaction;
 * claimed and processed by OutboxDispatcher (worker).
 */
final class OutboxMessage
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $companyId,
        public readonly string $aggregateType,
        public readonly string $aggregateId,
        public readonly string $eventType,
        public readonly array $payload,
        public readonly ?string $requestId = null,
        public readonly ?string $availableAt = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function make(
        string $companyId,
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        array $payload = [],
        ?string $requestId = null,
        ?string $availableAt = null,
    ): self {
        return new self(
            companyId: $companyId,
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            eventType: $eventType,
            payload: $payload,
            requestId: $requestId,
            availableAt: $availableAt,
        );
    }
}
