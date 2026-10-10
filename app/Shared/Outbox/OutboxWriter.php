<?php

declare(strict_types=1);

namespace App\Shared\Outbox;

use App\Shared\Company\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes side-effect intents into the transactional outbox.
 *
 * Call inside the same DB::transaction as the business writes + journal + audit.
 * The worker (OutboxDispatcher) drains pending rows with SKIP LOCKED.
 *
 * Schema reference: 09-events-jobs-queues.md.
 */
final class OutboxWriter
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
    }

    /**
     * @param  array{
     *     aggregate_type: string,
     *     aggregate_id: string,
     *     event_type: string,
     *     payload?: array<string, mixed>,
     *     request_id?: string|null,
     *     available_at?: string|null,
     *     company_id?: string|null
     * }  $data
     */
    public function record(array $data): void
    {
        $companyId = $data['company_id'] ?? $this->companyContext->companyId();
        $requestId = $data['request_id'] ?? $this->companyContext->requestId();

        DB::table('outbox')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'aggregate_type' => $data['aggregate_type'],
            'aggregate_id' => $data['aggregate_id'],
            'event_type' => $data['event_type'],
            'payload' => json_encode($data['payload'] ?? [], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => $data['available_at'] ?? now(),
            'locked_at' => null,
            'locked_by' => null,
            'last_error' => null,
            'processed_at' => null,
            'request_id' => $requestId,
            'created_at' => now(),
        ]);
    }

    public function recordMessage(OutboxMessage $message): void
    {
        $this->record([
            'company_id' => $message->companyId,
            'aggregate_type' => $message->aggregateType,
            'aggregate_id' => $message->aggregateId,
            'event_type' => $message->eventType,
            'payload' => $message->payload,
            'request_id' => $message->requestId,
            'available_at' => $message->availableAt,
        ]);
    }
}
