<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\Employee;
use App\Models\NumberSequence;
use App\Models\PayrollLine;
use App\Models\PayrollRuleVersion;
use App\Models\PayrollRun;
use App\Modules\Payroll\Services\PayrollCalculator;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/payroll/runs — create draft run for a month with lines from active employees.
 *
 * Calculation matches frontend Ghana rules (PAYE band widths, SSNIT on insurable basic,
 * junior overtime tax, 24 h monthly OT cap). Exceptions block approval until resolved.
 */
final class CreatePayrollRun
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
        private readonly PayrollCalculator $calculator,
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
        $periodMonth = (string) ($data['periodMonth'] ?? $data['period_month'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}$/', $periodMonth)) {
            throw new BusinessRuleException('periodMonth must be YYYY-MM.');
        }

        $overtimeByEmployee = $data['overtime'] ?? [];
        if (! is_array($overtimeByEmployee)) {
            $overtimeByEmployee = [];
        }

        $approvedOvertime = $data['overtimeApproved'] ?? $data['overtime_approved'] ?? [];
        if (! is_array($approvedOvertime)) {
            $approvedOvertime = [];
        }

        return DB::transaction(function () use ($companyId, $actorId, $periodMonth, $overtimeByEmployee, $approvedOvertime) {
            $exists = PayrollRun::query()
                ->where('company_id', $companyId)
                ->where('period_month', $periodMonth)
                ->exists();
            if ($exists) {
                throw new BusinessRuleException('Payroll run for this period already exists.');
            }

            $rule = PayrollRuleVersion::query()
                ->where('company_id', $companyId)
                ->where('effective_from', '<=', $periodMonth.'-01')
                ->where(function ($q) use ($periodMonth) {
                    $q->whereNull('effective_to')
                        ->orWhere('effective_to', '>=', $periodMonth.'-01');
                })
                ->orderByDesc('effective_from')
                ->first();

            $rules = $rule?->rules ?? $this->calculator->defaultRules();

            $runNo = $this->allocateRunNo($companyId, $periodMonth);
            $runId = Uuid::v7();

            $employees = Employee::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('employee_no')
                ->get();

            $grossTotal = 0;
            $ssfEmpTotal = 0;
            $ssfErTotal = 0;
            $payeTotal = 0;
            $otTaxTotal = 0;
            $netTotal = 0;
            $lineNo = 0;
            $exceptions = [];

            $run = new PayrollRun([
                'id' => $runId,
                'company_id' => $companyId,
                'run_no' => $runNo,
                'period_month' => $periodMonth,
                'status' => 'draft',
                'rule_version_id' => $rule?->id,
                'prepared_by' => $actorId,
                'request_id' => $this->companyContext->requestId(),
            ]);
            $run->save();

            foreach ($employees as $emp) {
                $lineNo++;
                $otHours = (int) ($overtimeByEmployee[$emp->id] ?? $overtimeByEmployee[$emp->employee_no] ?? 0);
                $isApproved = in_array($emp->id, $approvedOvertime, true)
                    || in_array($emp->employee_no, $approvedOvertime, true)
                    || ! empty($approvedOvertime[$emp->id])
                    || ! empty($approvedOvertime[$emp->employee_no]);

                $calc = $this->calculator->calculate($emp, $otHours, $rules, $isApproved);

                if ($calc['exception'] !== null) {
                    $exceptions[] = $calc['exception'];
                }

                $line = new PayrollLine([
                    'id' => Uuid::v7(),
                    'company_id' => $companyId,
                    'payroll_run_id' => $runId,
                    'employee_id' => $emp->id,
                    'line_no' => $lineNo,
                    'basic_minor' => $calc['basicMinor'],
                    'overtime_hours' => $calc['overtimeHours'],
                    'overtime_minor' => $calc['overtimeMinor'],
                    'allowances_minor' => $calc['allowancesMinor'],
                    'gross_minor' => $calc['grossMinor'],
                    'ssf_employee_minor' => $calc['ssfEmployeeMinor'],
                    'ssf_employer_minor' => $calc['ssfEmployerMinor'],
                    'paye_minor' => $calc['payeMinor'],
                    'ot_tax_minor' => $calc['otTaxMinor'],
                    'other_deductions_minor' => 0,
                    'net_minor' => $calc['netMinor'],
                    'is_frozen' => false,
                    'calculation_snapshot' => [
                        'ssnitEmp' => $rules['ssnitEmp'] ?? $rules['ssfEmployeePct'] ?? 5.5,
                        'ssnitEr' => $rules['ssnitEr'] ?? $rules['ssfEmployerPct'] ?? 13.0,
                        'insurableMinor' => $calc['insurableMinor'],
                        'tier1Minor' => $calc['tier1Minor'],
                        'tier2Minor' => $calc['tier2Minor'],
                        'assessableMinor' => $calc['assessableMinor'],
                        'reliefMinor' => $calc['reliefMinor'],
                        'taxableMinor' => $calc['taxableMinor'],
                        'isJunior' => $calc['isJunior'],
                        'isResident' => $calc['isResident'],
                        'otTaxMinor' => $calc['otTaxMinor'],
                    ],
                ]);
                $line->save();

                $grossTotal += $calc['grossMinor'];
                $ssfEmpTotal += $calc['ssfEmployeeMinor'];
                $ssfErTotal += $calc['ssfEmployerMinor'];
                $payeTotal += $calc['payeMinor'];
                $otTaxTotal += $calc['otTaxMinor'];
                $netTotal += $calc['netMinor'];
            }

            $run->gross_total_minor = $grossTotal;
            $run->ssf_employee_minor = $ssfEmpTotal;
            $run->ssf_employer_minor = $ssfErTotal;
            $run->paye_minor = $payeTotal;
            $run->ot_tax_total_minor = $otTaxTotal;
            $run->net_total_minor = $netTotal;
            $run->exceptions = $exceptions === [] ? null : $exceptions;
            $run->save();

            $this->auditWriter->write(
                action: 'payroll_run.created',
                entityType: 'payroll_run',
                entityId: $runId,
                detail: [
                    'period_month' => $periodMonth,
                    'line_count' => $lineNo,
                    'exception_count' => count($exceptions),
                ],
            );

            return [
                'id' => $runId,
                'runNo' => $runNo,
                'periodMonth' => $periodMonth,
                'status' => 'draft',
                'grossTotalMinor' => $grossTotal,
                'ssfEmployeeMinor' => $ssfEmpTotal,
                'ssfEmployerMinor' => $ssfErTotal,
                'payeMinor' => $payeTotal,
                'otTaxTotalMinor' => $otTaxTotal,
                'netTotalMinor' => $netTotal,
                'lineCount' => $lineNo,
                'exceptions' => $exceptions,
            ];
        });
    }

    private function allocateRunNo(string $companyId, string $periodMonth): string
    {
        $seq = NumberSequence::query()
            ->where('company_id', $companyId)
            ->where('doc_type', 'payroll_run')
            ->lockForUpdate()
            ->first();

        if ($seq === null) {
            $seq = new NumberSequence([
                'id' => Uuid::v7(),
                'company_id' => $companyId,
                'doc_type' => 'payroll_run',
                'fiscal_year' => (int) substr($periodMonth, 0, 4),
                'next_value' => 1,
            ]);
            $seq->save();
        }

        $n = (int) $seq->next_value;
        $seq->next_value = $n + 1;
        $seq->save();

        return sprintf('PR-%s-%04d', str_replace('-', '', $periodMonth), $n);
    }
}
