<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ledger;

use App\Exceptions\BusinessRuleException;
use App\Http\Requests\Ledger\LockPeriodRequest;
use App\Http\Requests\Ledger\ReopenPeriodRequest;
use App\Http\Requests\Ledger\WaiveCloseExceptionRequest;
use App\Modules\Ledger\Actions\LockPeriod;
use App\Modules\Ledger\Actions\ReopenPeriod;
use App\Modules\Ledger\Actions\WaiveCloseException;
use App\Modules\Ledger\Queries\PeriodCloseChecklistQuery;
use Illuminate\Http\JsonResponse;

final class PeriodCloseController
{
    public function show(string $month, PeriodCloseChecklistQuery $query): JsonResponse
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new BusinessRuleException('Month must be YYYY-MM.', 'invalid_month');
        }

        return response()->json($query->execute($month));
    }

    public function waive(string $month, WaiveCloseExceptionRequest $request, WaiveCloseException $action): JsonResponse
    {
        return response()->json($action->execute($month, $request->validated()), 201);
    }

    public function lock(string $month, LockPeriodRequest $request, LockPeriod $action): JsonResponse
    {
        return response()->json($action->execute($month, $request->validated()));
    }

    public function reopen(string $month, ReopenPeriodRequest $request, ReopenPeriod $action): JsonResponse
    {
        return response()->json($action->execute($month, $request->validated()));
    }
}
