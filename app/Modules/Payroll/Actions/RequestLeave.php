<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/leave — request leave for the current user's linked employee.
 */
final class RequestLeave
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
    public function execute(array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'people.leave.request');

        $companyId = $this->companyContext->companyId();
        $employee = $this->resolveEmployee($companyId, $actorId);

        $leaveType = (string) ($data['leaveType'] ?? 'Annual');
        $fromDate = (string) ($data['fromDate'] ?? '');
        $toDate = (string) ($data['toDate'] ?? '');
        $reason = isset($data['reason']) ? trim((string) $data['reason']) : null;

        if ($fromDate === '' || $toDate === '') {
            throw new BusinessRuleException('fromDate and toDate are required.');
        }
        if ($toDate < $fromDate) {
            throw new BusinessRuleException('toDate must be on or after fromDate.');
        }

        $days = $this->countDays($fromDate, $toDate);
        if ($days < 1) {
            throw new BusinessRuleException('Leave must be at least 1 day.');
        }

        $entitlement = 15;
        $used = $this->approvedCount($companyId, $employee->id);
        $remaining = $entitlement - $used;
        if ($remaining < 1) {
            throw new BusinessRuleException("No leave balance remaining this year. Entitlement: {$entitlement}.");
        }

        return DB::transaction(function () use (
            $companyId,
            $actorId,
            $employee,
            $leaveType,
            $fromDate,
            $toDate,
            $days,
            $reason,
            $remaining
        ) {
            $requestNo = $this->nextRequestNo($companyId);
            $id = Uuid::v7();

            $leave = new LeaveRequest([
                'id' => $id,
                'company_id' => $companyId,
                'request_no' => $requestNo,
                'employee_id' => $employee->id,
                'leave_type' => $leaveType,
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'days' => $days,
                'status' => 'pending',
                'reason' => $reason,
                'requested_by' => $actorId,
            ]);
            $leave->save();

            $this->auditWriter->write(
                action: 'leave.requested',
                entityType: 'leave_request',
                entityId: $id,
                detail: [
                    'request_no' => $requestNo,
                    'employee_id' => $employee->id,
                    'days' => $days,
                    'from' => $fromDate,
                    'to' => $toDate,
                ],
            );

            return $this->toArray($leave, $remaining - 1);
        });
    }

    private function resolveEmployee(string $companyId, string $actorId): Employee
    {
        $employee = Employee::query()
            ->where('company_id', $companyId)
            ->where('user_id', $actorId)
            ->where('is_active', true)
            ->first();

        if ($employee === null) {
            throw new BusinessRuleException('No active employee record is linked to your user account.');
        }

        return $employee;
    }

    private function approvedCount(string $companyId, string $employeeId): int
    {
        return (int) LeaveRequest::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->whereIn('status', ['approved', 'ongoing'])
            ->count();
    }

    private function countDays(string $from, string $to): int
    {
        $start = new \DateTimeImmutable($from);
        $end = new \DateTimeImmutable($to);

        return (int) $start->diff($end)->days + 1;
    }

    private function nextRequestNo(string $companyId): string
    {
        $year = date('Y');
        $prefix = "LV-{$year}-";
        $last = LeaveRequest::query()
            ->where('company_id', $companyId)
            ->where('request_no', 'like', $prefix.'%')
            ->orderByDesc('request_no')
            ->value('request_no');

        $seq = 1;
        if (is_string($last) && str_starts_with($last, $prefix)) {
            $seq = (int) substr($last, strlen($prefix)) + 1;
        }

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(LeaveRequest $leave, int $balanceAfter): array
    {
        return [
            'id' => $leave->id,
            'requestNo' => $leave->request_no,
            'employeeId' => $leave->employee_id,
            'leaveType' => $leave->leave_type,
            'fromDate' => $leave->from_date?->format('Y-m-d'),
            'toDate' => $leave->to_date?->format('Y-m-d'),
            'days' => (int) $leave->days,
            'status' => $leave->status,
            'reason' => $leave->reason,
            'balanceAfter' => $balanceAfter,
        ];
    }
}
