<?php

declare(strict_types=1);

namespace App\Http\Controllers\Logistics;

use App\Http\Requests\Logistics\StoreEtaRevisionRequest;
use App\Http\Requests\Logistics\StoreLandedCostRequest;
use App\Modules\Logistics\Actions\EnterLandedCost;
use App\Modules\Logistics\Actions\RecordEtaRevision;
use App\Modules\Logistics\Queries\ListEtaRevisionsQuery;
use App\Modules\Logistics\Queries\ListExceptionsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LogisticsSupportController
{
    public function __construct(
        private readonly EnterLandedCost $enterCost,
        private readonly RecordEtaRevision $recordEta,
        private readonly ListEtaRevisionsQuery $listEtas,
        private readonly ListExceptionsQuery $listExceptions,
    ) {
    }

    public function storeLandedCost(StoreLandedCostRequest $request): JsonResponse
    {
        return response()->json($this->enterCost->execute($request->validated()), 201);
    }

    public function listEtas(Request $request): JsonResponse
    {
        return response()->json($this->listEtas->execute(
            limit: (int) $request->query('limit', 50),
            offset: (int) $request->query('offset', 0),
        ));
    }

    public function storeEta(StoreEtaRevisionRequest $request): JsonResponse
    {
        return response()->json($this->recordEta->execute($request->validated()), 201);
    }

    public function exceptions(): JsonResponse
    {
        return response()->json($this->listExceptions->execute());
    }
}
