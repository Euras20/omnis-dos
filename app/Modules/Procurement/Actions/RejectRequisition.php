<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Requisition;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Outbox\OutboxWriter;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

final class RejectRequisition
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
        private readonly OutboxWriter $outboxWriter,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function execute(string $id, array $data = []): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'po.approve');
        $companyId = $this->companyContext->companyId();
        $reason = isset($data['reason']) ? trim((string) $data['reason']) : null;

        return DB::transaction(function () use ($id, $companyId, $actorId, $reason) {
            $req = Requisition::query()
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            if ($req === null) {
                throw new NotFoundException('Requisition not found.');
            }
            if (! in_array($req->status, ['pending', 'draft'], true)) {
                throw new BusinessRuleException("Cannot reject requisition in status '{$req->status}'.");
            }
            $req->status = 'rejected';
            $req->rejected_by = $actorId;
            $req->rejected_at = now();
            $req->reject_reason = $reason;
            $req->save();

            $this->auditWriter->write(
                action: 'requisition.rejected',
                entityType: 'requisition',
                entityId: $req->id,
                detail: ['reqNo' => $req->req_no, 'reason' => $reason],
                actorId: $actorId,
            );
            $this->outboxWriter->record([
                'aggregate_type' => 'requisition',
                'aggregate_id' => $req->id,
                'event_type' => 'requisition.rejected',
                'payload' => ['requisitionId' => $req->id],
            ]);

            return ['id' => $req->id, 'reqNo' => $req->req_no, 'status' => $req->status];
        });
    }
}
