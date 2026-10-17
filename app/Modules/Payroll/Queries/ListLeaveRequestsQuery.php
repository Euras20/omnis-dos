<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Queries;

use App\Models\LeaveRequest;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * GET /api/v1/leave — HR list of leave requests (requires people.leave.approve or payroll.manage).
 */
final class ListLeaveRequestsQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(?string $status, int $limit, int $offset): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'people.leave.approve');

        $companyId = $this->companyContext->companyId();
        $query = LeaveRequest::query()
            ->with('employee')
            ->where('company_id', $companyId);

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        $total = (clone $query)->count();
        $rows = $query
            ->orderByDesc('created_at')
            ->limit($limit)
            ->offset($offset)
            ->get()
            ->map(fn (LeaveRequest $l) => [
                'id' => $l->id,
                'requestNo' => $l->request_no,
                'employeeId' => $l->employee_id,
                'employeeNo' => $l->employee?->employee_no,
                'employeeName' => trim(($l->employee?->first_name ?? '').' '.($l->employee?->last_name ?? '')),
                'leaveType' => $l->leave_type,
                'fromDate' => $l->from_date?->format('Y-m-d'),
                'toDate' => $l->to_date?->format('Y-m-d'),
                'days' => (int) $l->days,
                'status' => $l->status,
                'reason' => $l->reason,
                'rejectionReason' => $l->rejection_reason,
                'requestedBy' => $l->requested_by,
                'decidedBy' => $l->decided_by,
                'decidedAt' => $l->decided_at?->toIso8601String(),
                'createdAt' => $l->created_at?->toIso8601String(),
            ]);

        return [
            'data' => $rows,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }
}
