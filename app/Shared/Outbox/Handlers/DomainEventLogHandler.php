<?php

declare(strict_types=1);

namespace App\Shared\Outbox\Handlers;

use Illuminate\Support\Facades\Log;

/**
 * Lightweight handler for domain-notification style event types
 * (invoice.posted, receipt.posted, payroll.posted, etc.).
 *
 * In this phase these events are informational: structured log only.
 * Future phases may fan out to user notification preferences.
 *
 * Registered for multiple event_types that share the same shape.
 */
final class DomainEventLogHandler
{
    public function __invoke(array $payload): void
    {
        Log::info('Domain outbox event', [
            'payload_keys' => array_keys($payload),
            'aggregate' => $payload['invoiceId']
                ?? $payload['receiptId']
                ?? $payload['paymentId']
                ?? $payload['runId']
                ?? $payload['billId']
                ?? $payload['orderId']
                ?? null,
        ]);
    }
}
