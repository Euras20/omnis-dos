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
 * POST /api/v1/leave/{id}/reject
 */
final class RejectLeave
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function execute(string $id, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'people.leave.approve');

        $companyId = $this->companyContext->companyId();
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new BusinessRuleException('Rejection reason is required.');
        }

        return DB::transaction(function () use ($companyId, $actorId, $id, $reason) {
            $leave = LeaveRequest::query()
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($leave === null) {
                throw new BusinessRuleException('Leave request not found.');
            }
            if ($leave->status !== 'pending') {
                throw new BusinessRuleException('Only pending leave requests can be rejected.');
            }

            $leave->status = 'rejected';
            $leave->rejection_reason = $reason;
            $leave->decided_by = $actorId;
            $leave->decided_at = now();
            $leave->save();

            $this->auditWriter->write(
                action: 'leave.rejected',
                entityType: 'leave_request',
                entityId: $id,
                detail: [
                    'request_no' => $leave->request_no,
                    'reason' => $reason,
                ],
            );

            return [
                'id' => $leave->id,
                'requestNo' => $leave->request_no,
                'status' => $leave->status,
                'rejectionReason' => $leave->rejection_reason,
                'decidedBy' => $actorId,
                'decidedAt' => $leave->decided_at?->toIso8601String(),
            ];
        });
    }
}
