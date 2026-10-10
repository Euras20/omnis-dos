<?php

declare(strict_types=1);

namespace App\Http\Controllers\Treasury;

use App\Http\Controllers\Controller;
use App\Models\CashExpense;
use App\Modules\Treasury\Actions\PostCashExpense;
use App\Shared\Company\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ExpenseController extends Controller
{
    public function index(CompanyContext $companyContext): JsonResponse
    {
        $rows = CashExpense::query()
            ->where('company_id', $companyContext->companyId())
            ->orderByDesc('expense_date')
            ->limit(200)
            ->get()
            ->map(fn (CashExpense $e) => [
                'id' => $e->id,
                'expenseNo' => $e->expense_no,
                'expenseDate' => $e->expense_date?->format('Y-m-d'),
                'paidFrom' => $e->paid_from,
                'grossMinor' => (int) $e->gross_minor,
                'payee' => $e->payee,
                'journalId' => $e->journal_id,
            ]);

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request, PostCashExpense $action): JsonResponse
    {
        return response()->json($action->execute($request->all()), 201);
    }
}
