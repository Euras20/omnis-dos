<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Modules\Payroll\Actions\ApprovePayrollRun;
use App\Modules\Payroll\Actions\CreatePayrollRun;
use App\Modules\Payroll\Actions\PayPayrollRun;
use App\Modules\Payroll\Actions\PostPayrollRun;
use App\Shared\Company\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PayrollRunController extends Controller
{
    public function index(CompanyContext $companyContext): JsonResponse
    {
        $rows = PayrollRun::query()
            ->where('company_id', $companyContext->companyId())
            ->orderByDesc('period_month')
            ->get()
            ->map(fn (PayrollRun $r) => [
                'id' => $r->id,
                'runNo' => $r->run_no,
                'periodMonth' => $r->period_month,
                'status' => $r->status,
                'grossTotalMinor' => (int) $r->gross_total_minor,
                'netTotalMinor' => (int) $r->net_total_minor,
                'payeMinor' => (int) $r->paye_minor,
            ]);

        return response()->json(['data' => $rows]);
    }

    public function show(string $id, CompanyContext $companyContext): JsonResponse
    {
        $run = PayrollRun::query()
            ->where('company_id', $companyContext->companyId())
            ->where('id', $id)
            ->firstOrFail();

        $lines = PayrollLine::query()
            ->where('payroll_run_id', $id)
            ->orderBy('line_no')
            ->get()
            ->map(fn (PayrollLine $l) => [
                'id' => $l->id,
                'employeeId' => $l->employee_id,
                'lineNo' => $l->line_no,
                'basicMinor' => (int) $l->basic_minor,
                'overtimeMinor' => (int) $l->overtime_minor,
                'grossMinor' => (int) $l->gross_minor,
                'ssfEmployeeMinor' => (int) $l->ssf_employee_minor,
                'ssfEmployerMinor' => (int) $l->ssf_employer_minor,
                'payeMinor' => (int) $l->paye_minor,
                'netMinor' => (int) $l->net_minor,
                'isFrozen' => (bool) $l->is_frozen,
            ]);

        return response()->json([
            'id' => $run->id,
            'runNo' => $run->run_no,
            'periodMonth' => $run->period_month,
            'status' => $run->status,
            'grossTotalMinor' => (int) $run->gross_total_minor,
            'ssfEmployeeMinor' => (int) $run->ssf_employee_minor,
            'ssfEmployerMinor' => (int) $run->ssf_employer_minor,
            'payeMinor' => (int) $run->paye_minor,
            'netTotalMinor' => (int) $run->net_total_minor,
            'journalId' => $run->journal_id,
            'paymentJournalId' => $run->payment_journal_id,
            'bankRef' => $run->bank_ref,
            'lines' => $lines,
        ]);
    }

    public function store(Request $request, CreatePayrollRun $action): JsonResponse
    {
        return response()->json($action->execute($request->all()), 201);
    }

    public function approve(string $id, ApprovePayrollRun $action): JsonResponse
    {
        return response()->json($action->execute($id));
    }

    public function post(string $id, PostPayrollRun $action): JsonResponse
    {
        return response()->json($action->execute($id));
    }

    public function pay(string $id, Request $request, PayPayrollRun $action): JsonResponse
    {
        return response()->json($action->execute($id, $request->all()));
    }
}
