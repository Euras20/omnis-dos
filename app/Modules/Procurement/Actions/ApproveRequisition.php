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

final class ApproveRequisition
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
        private readonly OutboxWriter $outboxWriter,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $id): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'po.approve');
        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($id, $companyId, $actorId) {
            $req = Requisition::query()
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            if ($req === null) {
                throw new NotFoundException('Requisition not found.');
            }
            if (! in_array($req->status, ['pending', 'draft'], true)) {
                throw new BusinessRuleException("Cannot approve requisition in status '{$req->status}'.");
            }
            if (
                $req->requested_by !== null
                && $req->requested_by === $actorId
                && ! $this->permissionChecker->isSystemAdministrator($actorId)
            ) {
                throw new BusinessRuleException('Segregation of duties: approver must differ from requester.');
            }
            $req->status = 'approved';
            $req->approved_by = $actorId;
            $req->approved_at = now();
            $req->save();

            $this->auditWriter->write(
                action: 'requisition.approved',
                entityType: 'requisition',
                entityId: $req->id,
                detail: ['reqNo' => $req->req_no],
                actorId: $actorId,
            );
            $this->outboxWriter->record([
                'aggregate_type' => 'requisition',
                'aggregate_id' => $req->id,
                'event_type' => 'requisition.approved',
                'payload' => ['requisitionId' => $req->id],
            ]);

            return ['id' => $req->id, 'reqNo' => $req->req_no, 'status' => $req->status];
        });
    }
}
