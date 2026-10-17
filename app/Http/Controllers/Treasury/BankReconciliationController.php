<?php

declare(strict_types=1);

namespace App\Http\Controllers\Treasury;

use App\Http\Controllers\Controller;
use App\Modules\Treasury\Actions\ApproveBankReconciliation;
use App\Modules\Treasury\Actions\PrepareBankReconciliation;
use App\Modules\Treasury\Queries\BankReconciliationWorkbenchQuery;
use App\Modules\Treasury\Queries\ListBankReconciliationsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BankReconciliationController extends Controller
{
    public function workbench(Request $request, BankReconciliationWorkbenchQuery $query): JsonResponse
    {
        return response()->json($query->execute($request->query()));
    }

    public function index(Request $request, ListBankReconciliationsQuery $query): JsonResponse
    {
        return response()->json($query->execute($request->query()));
    }

    public function store(Request $request, PrepareBankReconciliation $action): JsonResponse
    {
        return response()->json($action->execute($request->all()), 201);
    }

    public function approve(string $id, ApproveBankReconciliation $action): JsonResponse
    {
        return response()->json($action->execute($id));
    }
}
