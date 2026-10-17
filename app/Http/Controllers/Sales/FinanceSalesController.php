<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Modules\Sales\Queries\SalesFinanceActivityQuery;
use App\Modules\Sales\Queries\SalesFinanceSummaryQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FinanceSalesController
{
    public function summary(SalesFinanceSummaryQuery $query): JsonResponse
    {
        return response()->json($query->execute());
    }

    public function activity(Request $request, SalesFinanceActivityQuery $query): JsonResponse
    {
        return response()->json($query->execute([
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'type' => $request->query('type'),
            'status' => $request->query('status'),
            'limit' => $request->query('limit'),
            'offset' => $request->query('offset'),
        ]));
    }
}
