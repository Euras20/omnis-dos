<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\Employee;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/employees
 */
final class CreateEmployee
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
        $this->permissionChecker->assertCan($actorId, 'payroll.manage');

        $companyId = $this->companyContext->companyId();
        $employeeNo = trim((string) ($data['employeeNo'] ?? $data['employee_no'] ?? ''));
        $firstName = trim((string) ($data['firstName'] ?? $data['first_name'] ?? ''));
        $lastName = trim((string) ($data['lastName'] ?? $data['last_name'] ?? ''));
        $hireDate = (string) ($data['hireDate'] ?? $data['hire_date'] ?? '');

        if ($employeeNo === '' || $firstName === '' || $lastName === '') {
            throw new BusinessRuleException('employeeNo, firstName and lastName are required.');
        }
        if ($hireDate === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $hireDate)) {
            throw new BusinessRuleException('hireDate must be YYYY-MM-DD.');
        }

        $basicMinor = (int) ($data['basicMinor'] ?? $data['basic_minor'] ?? 0);
        if ($basicMinor < 0) {
            throw new BusinessRuleException('basicMinor must be >= 0.');
        }

        return DB::transaction(function () use (
            $companyId,
            $actorId,
            $employeeNo,
            $firstName,
            $lastName,
            $hireDate,
            $basicMinor,
            $data
        ) {
            $exists = Employee::query()
                ->where('company_id', $companyId)
                ->where('employee_no', $employeeNo)
                ->exists();
            if ($exists) {
                throw new BusinessRuleException('Employee number already exists.');
            }

            $id = Uuid::v7();
            $employee = new Employee([
                'id' => $id,
                'company_id' => $companyId,
                'employee_no' => $employeeNo,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'other_names' => isset($data['otherNames']) ? trim((string) $data['otherNames']) : null,
                'date_of_birth' => $data['dateOfBirth'] ?? null,
                'hire_date' => $hireDate,
                'ssnit_number' => $data['ssnitNumber'] ?? $data['ssnit_number'] ?? null,
                'tin' => $data['tin'] ?? null,
                'bank_account' => $data['bankAccount'] ?? null,
                'bank_name' => $data['bankName'] ?? null,
                'department' => $data['department'] ?? null,
                'job_title' => $data['jobTitle'] ?? $data['job_title'] ?? null,
                'basic_minor' => $basicMinor,
                'currency' => strtoupper((string) ($data['currency'] ?? 'GHS')),
                'is_active' => true,
                'created_by' => $actorId,
            ]);
            $employee->save();

            $this->auditWriter->write(
                action: 'employee.created',
                entityType: 'employee',
                entityId: $id,
                detail: ['employee_no' => $employeeNo],
            );

            return $this->toArray($employee);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(Employee $e): array
    {
        return [
            'id' => $e->id,
            'employeeNo' => $e->employee_no,
            'firstName' => $e->first_name,
            'lastName' => $e->last_name,
            'otherNames' => $e->other_names,
            'hireDate' => $e->hire_date?->format('Y-m-d'),
            'basicMinor' => (int) $e->basic_minor,
            'currency' => $e->currency,
            'department' => $e->department,
            'jobTitle' => $e->job_title,
            'isActive' => (bool) $e->is_active,
            'ssnitNumber' => $e->ssnit_number,
            'tin' => $e->tin,
        ];
    }
}
