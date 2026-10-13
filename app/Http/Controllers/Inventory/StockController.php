<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Requests\Inventory\ApproveStockCountRequest;
use App\Http\Requests\Inventory\DecideAssignmentRequest;
use App\Http\Requests\Inventory\StoreAssignmentRequest;
use App\Http\Requests\Inventory\StoreStockCountRequest;
use App\Http\Requests\Inventory\StoreStockTransferRequest;
use App\Modules\Inventory\Actions\ApproveStockCount;
use App\Modules\Inventory\Actions\CreateAssignment;
use App\Modules\Inventory\Actions\CreateStockCount;
use App\Modules\Inventory\Actions\DecideAssignment;
use App\Modules\Inventory\Actions\PostStockTransfer;
use App\Modules\Inventory\Actions\SubmitStockCount;
use App\Modules\Inventory\Queries\ListAssignmentsQuery;
use App\Modules\Inventory\Queries\ListStockCountsQuery;
use App\Modules\Inventory\Queries\ListStockMovementsQuery;
use App\Modules\Inventory\Queries\ListStockTransfersQuery;
use App\Modules\Inventory\Queries\OrderTrackingQuery;
use App\Modules\Inventory\Queries\StockOnHandQuery;
use App\Modules\Inventory\Queries\TraceabilityQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StockController
{
    public function __construct(
        private readonly StockOnHandQuery $stockOnHandQuery,
        private readonly ListAssignmentsQuery $listAssignmentsQuery,
        private readonly CreateAssignment $createAssignment,
        private readonly DecideAssignment $decideAssignment,
        private readonly ListStockMovementsQuery $listStockMovementsQuery,
        private readonly TraceabilityQuery $traceabilityQuery,
        private readonly ListStockTransfersQuery $listStockTransfersQuery,
        private readonly PostStockTransfer $postStockTransfer,
        private readonly ListStockCountsQuery $listStockCountsQuery,
        private readonly CreateStockCount $createStockCount,
        private readonly SubmitStockCount $submitStockCount,
        private readonly ApproveStockCount $approveStockCount,
        private readonly OrderTrackingQuery $orderTrackingQuery,
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
        return response()->json($this->createAssignment->execute($request->validated()), 201);
    }

    public function decideAssignment(string $id, DecideAssignmentRequest $request): JsonResponse
    {
        return response()->json($this->decideAssignment->execute($id, $request->validated()));
    }

    public function movements(Request $request): JsonResponse
    {
        return response()->json($this->listStockMovementsQuery->execute($this->movementFilters($request)));
    }

    public function ledger(Request $request): JsonResponse
    {
        return response()->json($this->listStockMovementsQuery->execute($this->movementFilters($request)));
    }

    public function traceability(string $lot): JsonResponse
    {
        return response()->json($this->traceabilityQuery->execute($lot));
    }

    public function transfers(Request $request): JsonResponse
    {
        return response()->json($this->listStockTransfersQuery->execute([
            'itemId' => $request->query('itemId'),
            'fromLocationId' => $request->query('fromLocationId'),
            'toLocationId' => $request->query('toLocationId'),
            'status' => $request->query('status'),
            'limit' => $request->query('limit'),
            'offset' => $request->query('offset'),
        ]));
    }

    public function storeTransfer(StoreStockTransferRequest $request): JsonResponse
    {
        return response()->json($this->postStockTransfer->execute($request->validated()), 201);
    }

    public function counts(Request $request): JsonResponse
    {
        return response()->json($this->listStockCountsQuery->execute([
            'status' => $request->query('status'),
            'locationId' => $request->query('locationId'),
            'limit' => $request->query('limit'),
            'offset' => $request->query('offset'),
        ]));
    }

    public function showCount(string $id): JsonResponse
    {
        return response()->json($this->listStockCountsQuery->get($id));
    }

    public function storeCount(StoreStockCountRequest $request): JsonResponse
    {
        return response()->json($this->createStockCount->execute($request->validated()), 201);
    }

    public function submitCount(string $id): JsonResponse
    {
        return response()->json($this->submitStockCount->execute($id));
    }

    public function approveCount(string $id, ApproveStockCountRequest $request): JsonResponse
    {
        return response()->json($this->approveStockCount->execute($id, $request->validated()));
    }

    public function tracking(Request $request): JsonResponse
    {
        $q = $request->query('q');

        return response()->json($this->orderTrackingQuery->execute(
            $q !== null ? (string) $q : null
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function movementFilters(Request $request): array
    {
        return [
            'itemId' => $request->query('itemId'),
            'locationId' => $request->query('locationId'),
            'lot' => $request->query('lot'),
            'movementType' => $request->query('movementType'),
            'sourceType' => $request->query('sourceType'),
            'sourceId' => $request->query('sourceId'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'q' => $request->query('q'),
            'limit' => $request->query('limit'),
            'offset' => $request->query('offset'),
        ];
    }
}
