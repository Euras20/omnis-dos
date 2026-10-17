<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\LeaveRequest;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/leave/{id}/approve
 */
final class ApproveLeave
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $id): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'people.leave.approve');

        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($companyId, $actorId, $id) {
            $leave = LeaveRequest::query()
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($leave === null) {
                throw new BusinessRuleException('Leave request not found.');
            }
            if ($leave->status !== 'pending') {
                throw new BusinessRuleException('Only pending leave requests can be approved.');
            }

            $leave->status = 'approved';
            $leave->decided_by = $actorId;
            $leave->decided_at = now();
            $leave->save();

            $this->auditWriter->write(
                action: 'leave.approved',
                entityType: 'leave_request',
                entityId: $id,
                detail: ['request_no' => $leave->request_no],
            );

            return [
                'id' => $leave->id,
                'requestNo' => $leave->request_no,
                'status' => $leave->status,
                'decidedBy' => $actorId,
                'decidedAt' => $leave->decided_at?->toIso8601String(),
            ];
        });
    }
}
