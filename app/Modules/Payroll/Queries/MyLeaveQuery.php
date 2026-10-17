<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Queries;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Shared\Company\CompanyContext;

/**
 * GET /api/v1/leave/me — current user's leave balance and requests.
 */
final class MyLeaveQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $companyId = $this->companyContext->companyId();
        $actorId = $this->companyContext->userId();

        $employee = Employee::query()
            ->where('company_id', $companyId)
            ->where('user_id', $actorId)
            ->where('is_active', true)
            ->first();

        if ($employee === null) {
            return [
                'employee' => null,
                'entitlement' => 0,
                'used' => 0,
                'remaining' => 0,
                'requests' => [],
            ];
        }

        $requests = LeaveRequest::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (LeaveRequest $l) => $this->toArray($l));

        $used = (int) LeaveRequest::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->whereIn('status', ['approved', 'ongoing'])
            ->count();

        $entitlement = 15;

        return [
            'employee' => [
                'id' => $employee->id,
                'employeeNo' => $employee->employee_no,
                'firstName' => $employee->first_name,
                'lastName' => $employee->last_name,
            ],
            'entitlement' => $entitlement,
            'used' => $used,
            'remaining' => max(0, $entitlement - $used),
            'requests' => $requests,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(LeaveRequest $l): array
    {
        $status = $this->effectiveStatus($l);

        return [
            'id' => $l->id,
            'requestNo' => $l->request_no,
            'leaveType' => $l->leave_type,
            'fromDate' => $l->from_date?->format('Y-m-d'),
            'toDate' => $l->to_date?->format('Y-m-d'),
            'days' => (int) $l->days,
            'status' => $status,
            'reason' => $l->reason,
            'rejectionReason' => $l->rejection_reason,
            'createdAt' => $l->created_at?->toIso8601String(),
        ];
    }

    private function effectiveStatus(LeaveRequest $l): string
    {
        if ($l->status === 'rejected' || $l->status === 'pending') {
            return $l->status;
        }

        $today = now()->toDateString();
        $from = $l->from_date?->format('Y-m-d') ?? '';
        $to = $l->to_date?->format('Y-m-d') ?? '';

        if ($from !== '' && $today >= $from && $today <= $to) {
            return 'ongoing';
        }
        if ($to !== '' && $today > $to) {
            return 'expired';
        }

        return 'approved';
    }
}
