<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\Employee;
use App\Models\NumberSequence;
use App\Models\PayrollLine;
use App\Models\PayrollRuleVersion;
use App\Models\PayrollRun;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/payroll/runs — create draft run for a month with lines from active employees.
 */
final class CreatePayrollRun
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
        $periodMonth = (string) ($data['periodMonth'] ?? $data['period_month'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}$/', $periodMonth)) {
            throw new BusinessRuleException('periodMonth must be YYYY-MM.');
        }

        $overtimeByEmployee = $data['overtime'] ?? [];
        if (! is_array($overtimeByEmployee)) {
            $overtimeByEmployee = [];
        }

        return DB::transaction(function () use ($companyId, $actorId, $periodMonth, $overtimeByEmployee) {
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

            $rules = $rule?->rules ?? [
                'ssfEmployeePct' => 5.5,
                'ssfEmployerPct' => 13.0,
                'payeBands' => [
                    ['upTo' => 49000, 'rate' => 0],
                    ['upTo' => 110000, 'rate' => 5],
                    ['upTo' => 130000, 'rate' => 10],
                    ['upTo' => 3000000, 'rate' => 17.5],
                    ['upTo' => null, 'rate' => 25],
                ],
                'overtimeMultiplier' => 1.5,
            ];

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
            $netTotal = 0;
            $lineNo = 0;

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
                $basic = (int) $emp->basic_minor;
                $otHours = (int) ($overtimeByEmployee[$emp->id] ?? $overtimeByEmployee[$emp->employee_no] ?? 0);
                $hourly = $basic > 0 ? (int) round($basic / 173.33) : 0; // approx monthly hours
                $multiplier = (float) ($rules['overtimeMultiplier'] ?? 1.5);
                $otMinor = (int) round($otHours * $hourly * $multiplier);
                $allowances = 0;
                $gross = $basic + $otMinor + $allowances;

                $ssfEmpPct = (float) ($rules['ssfEmployeePct'] ?? 5.5);
                $ssfErPct = (float) ($rules['ssfEmployerPct'] ?? 13.0);
                $ssfEmp = (int) round($gross * $ssfEmpPct / 100);
                $ssfEr = (int) round($gross * $ssfErPct / 100);

                $taxable = $gross - $ssfEmp;
                $paye = $this->computePaye($taxable, $rules['payeBands'] ?? []);
                $net = $gross - $ssfEmp - $paye;

                $line = new PayrollLine([
                    'id' => Uuid::v7(),
                    'company_id' => $companyId,
                    'payroll_run_id' => $runId,
                    'employee_id' => $emp->id,
                    'line_no' => $lineNo,
                    'basic_minor' => $basic,
                    'overtime_hours' => $otHours,
                    'overtime_minor' => $otMinor,
                    'allowances_minor' => $allowances,
                    'gross_minor' => $gross,
                    'ssf_employee_minor' => $ssfEmp,
                    'ssf_employer_minor' => $ssfEr,
                    'paye_minor' => $paye,
                    'other_deductions_minor' => 0,
                    'net_minor' => $net,
                    'is_frozen' => false,
                    'calculation_snapshot' => [
                        'ssfEmployeePct' => $ssfEmpPct,
                        'ssfEmployerPct' => $ssfErPct,
                    ],
                ]);
                $line->save();

                $grossTotal += $gross;
                $ssfEmpTotal += $ssfEmp;
                $ssfErTotal += $ssfEr;
                $payeTotal += $paye;
                $netTotal += $net;
            }

            $run->gross_total_minor = $grossTotal;
            $run->ssf_employee_minor = $ssfEmpTotal;
            $run->ssf_employer_minor = $ssfErTotal;
            $run->paye_minor = $payeTotal;
            $run->net_total_minor = $netTotal;
            $run->save();

            $this->auditWriter->write(
                action: 'payroll_run.created',
                entityType: 'payroll_run',
                entityId: $runId,
                detail: ['period_month' => $periodMonth, 'line_count' => $lineNo],
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
                'netTotalMinor' => $netTotal,
                'lineCount' => $lineNo,
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

    /**
     * @param  array<int, array{upTo: ?int, rate: float|int}>  $bands
     */
    private function computePaye(int $taxableMinor, array $bands): int
    {
        if ($taxableMinor <= 0 || $bands === []) {
            return 0;
        }

        $remaining = $taxableMinor;
        $tax = 0;
        $prevUpTo = 0;

        foreach ($bands as $band) {
            $upTo = $band['upTo'] ?? null;
            $rate = (float) ($band['rate'] ?? 0);
            if ($upTo === null) {
                $tax += (int) round($remaining * $rate / 100);
                break;
            }
            $slice = min($remaining, (int) $upTo - $prevUpTo);
            if ($slice > 0) {
                $tax += (int) round($slice * $rate / 100);
                $remaining -= $slice;
            }
            $prevUpTo = (int) $upTo;
            if ($remaining <= 0) {
                break;
            }
        }

        return max(0, $tax);
    }
}
