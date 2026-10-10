<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Requests\Inventory\DecideAssignmentRequest;
use App\Http\Requests\Inventory\StoreAssignmentRequest;
use App\Modules\Inventory\Actions\CreateAssignment;
use App\Modules\Inventory\Actions\DecideAssignment;
use App\Modules\Inventory\Queries\ListAssignmentsQuery;
use App\Modules\Inventory\Queries\StockOnHandQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StockController
{
    public function __construct(
        private readonly StockOnHandQuery $stockOnHandQuery,
        private readonly ListAssignmentsQuery $listAssignmentsQuery,
        private readonly CreateAssignment $createAssignment,
        private readonly DecideAssignment $decideAssignment,
    ) {
    }

    public function onHand(Request $request): JsonResponse
    {
        return response()->json($this->stockOnHandQuery->execute([
            'itemId' => $request->query('itemId'),
            'locationId' => $request->query('locationId'),
            'lot' => $request->query('lot'),
            'q' => $request->query('q'),
            'limit' => $request->query('limit'),
            'offset' => $request->query('offset'),
        ]));
    }

    public function assignments(Request $request): JsonResponse
    {
        return response()->json($this->listAssignmentsQuery->execute([
            'status' => $request->query('status'),
            'itemId' => $request->query('itemId'),
            'locationId' => $request->query('locationId'),
            'salesOrderLineId' => $request->query('salesOrderLineId'),
            'limit' => $request->query('limit'),
            'offset' => $request->query('offset'),
        ]));
    }

    public function storeAssignment(StoreAssignmentRequest $request): JsonResponse
    {
        $assignment = $this->createAssignment->execute($request->validated());

        return response()->json($assignment, 201);
    }

    public function decideAssignment(string $id, DecideAssignmentRequest $request): JsonResponse
    {
        $result = $this->decideAssignment->execute($id, $request->validated());

        return response()->json($result);
    }
}
