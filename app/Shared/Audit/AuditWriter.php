<?php

declare(strict_types=1);

namespace App\Shared\Audit;

use App\Shared\Company\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Append-only writer for the legal audit trail (table: audit_event).
 *
 * Must be called inside the same DB transaction as the business command
 * (08-transactions-and-consistency.md, 10-auditability-and-logging.md).
 * Never write audit records outside a successful command transaction.
 */
final class AuditWriter
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
    }

    /**
     * Record a business event.
     *
     * @param  array<string, mixed>  $detail  Structured context (IDs, amounts, before/after where useful).
     *                                        Must not contain secrets or full PII beyond what the UI already shows.
     */
    public function write(
        string $action,
        string $entityType,
        ?string $entityId = null,
        array $detail = [],
        ?string $actorId = null,
    ): void {
        $actorId ??= $this->companyContext->userId();
        $requestId = $this->companyContext->requestId();

        DB::table('audit_event')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $this->companyContext->companyId(),
            'actor_id' => $actorId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'request_id' => $requestId,
            'detail' => $detail === [] ? null : json_encode($detail, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
