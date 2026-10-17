<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Employee;
use App\Models\PayrollLine;
use App\Models\PayrollRuleVersion;
use App\Models\PayrollRun;
use App\Modules\Payroll\Services\PayrollCalculator;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/payroll/runs/{id}/cap-overtime — while draft, cap OT at the monthly limit
 * (default 24 h) and recalculate affected lines. Clears the corresponding exceptions.
 */
final class CapOvertime
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
        private readonly PayrollCalculator $calculator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $runId): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'payroll.manage');

        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($companyId, $actorId, $runId) {
            $run = PayrollRun::query()
                ->where('company_id', $companyId)
                ->where('id', $runId)
                ->lockForUpdate()
                ->first();

            if ($run === null) {
                throw new NotFoundException('Payroll run not found.');
            }
            if ($run->status !== 'draft') {
                throw new BusinessRuleException('Only draft payroll runs can have overtime capped.');
            }

            $rules = $this->resolveRules($companyId, $run);
            $capHours = (int) ($rules['otMonthlyCapHours'] ?? 24);

            $lines = PayrollLine::query()
                ->where('payroll_run_id', $runId)
                ->with('employee')
                ->orderBy('line_no')
                ->get();

            $capped = 0;
            foreach ($lines as $line) {
                $otHours = (int) $line->overtime_hours;
                if ($otHours <= $capHours) {
                    continue;
                }

                $emp = $line->employee;
                if ($emp === null) {
                    continue;
                }

                $calc = $this->calculator->calculate($emp, $capHours, $rules, true);
                $line->overtime_hours = $calc['overtimeHours'];
                $line->overtime_minor = $calc['overtimeMinor'];
                $line->allowances_minor = $calc['allowancesMinor'];
                $line->gross_minor = $calc['grossMinor'];
                $line->ssf_employee_minor = $calc['ssfEmployeeMinor'];
                $line->ssf_employer_minor = $calc['ssfEmployerMinor'];
                $line->paye_minor = $calc['payeMinor'];
                $line->ot_tax_minor = $calc['otTaxMinor'];
                $line->net_minor = $calc['netMinor'];
                $line->calculation_snapshot = array_merge($line->calculation_snapshot ?? [], [
                    'insurableMinor' => $calc['insurableMinor'],
                    'tier1Minor' => $calc['tier1Minor'],
                    'tier2Minor' => $calc['tier2Minor'],
                    'assessableMinor' => $calc['assessableMinor'],
                    'reliefMinor' => $calc['reliefMinor'],
                    'taxableMinor' => $calc['taxableMinor'],
                    'isJunior' => $calc['isJunior'],
                    'otTaxMinor' => $calc['otTaxMinor'],
                    'cappedFromHours' => $otHours,
                ]);
                $line->save();
                $capped++;
            }

            // Recompute run totals and exceptions from all lines
            $grossTotal = 0;
            $ssfEmpTotal = 0;
            $ssfErTotal = 0;
            $payeTotal = 0;
            $otTaxTotal = 0;
            $netTotal = 0;
            $exceptions = [];

            foreach ($lines as $line) {
                $line->refresh();
                $grossTotal += (int) $line->gross_minor;
                $ssfEmpTotal += (int) $line->ssf_employee_minor;
                $ssfErTotal += (int) $line->ssf_employer_minor;
                $payeTotal += (int) $line->paye_minor;
                $otTaxTotal += (int) $line->ot_tax_minor;
                $netTotal += (int) $line->net_minor;

                $emp = $line->employee;
                if ($emp !== null && (int) $line->overtime_hours > $capHours) {
                    $name = trim($emp->first_name.' '.$emp->last_name);
                    $exceptions[] = $name.': '.$line->overtime_hours.' overtime hours exceed '.$capHours.' h monthly cap and are not approved';
                }
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
                action: 'payroll_run.overtime_capped',
                entityType: 'payroll_run',
                entityId: $runId,
                detail: ['capped_lines' => $capped, 'cap_hours' => $capHours],
            );

            return [
                'id' => $run->id,
                'runNo' => $run->run_no,
                'periodMonth' => $run->period_month,
                'status' => $run->status,
                'cappedLines' => $capped,
                'capHours' => $capHours,
                'exceptions' => $exceptions,
                'grossTotalMinor' => $grossTotal,
                'payeMinor' => $payeTotal,
                'otTaxTotalMinor' => $otTaxTotal,
                'netTotalMinor' => $netTotal,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveRules(string $companyId, PayrollRun $run): array
    {
        if ($run->rule_version_id !== null) {
            $rule = PayrollRuleVersion::query()
                ->where('company_id', $companyId)
                ->where('id', $run->rule_version_id)
                ->first();
            if ($rule !== null && is_array($rule->rules)) {
                return $rule->rules;
            }
        }

        return $this->calculator->defaultRules();
    }
}
