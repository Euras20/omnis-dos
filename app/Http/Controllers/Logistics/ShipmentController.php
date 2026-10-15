<?php

declare(strict_types=1);

namespace App\Http\Controllers\Logistics;

use App\Http\Requests\Logistics\MarkConsignmentReceivedRequest;
use App\Http\Requests\Logistics\RecordShipmentEventRequest;
use App\Http\Requests\Logistics\StoreShipmentRequest;
use App\Modules\Logistics\Actions\CreateShipment;
use App\Modules\Logistics\Actions\MarkConsignmentReceived;
use App\Modules\Logistics\Actions\RecordShipmentEvent;
use App\Modules\Logistics\Queries\GetShipmentQuery;
use App\Modules\Logistics\Queries\LandedCostWorksheetQuery;
use App\Modules\Logistics\Queries\ListShipmentsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShipmentController
{
    public function __construct(
        private readonly ListShipmentsQuery $listQuery,
        private readonly GetShipmentQuery $getQuery,
        private readonly LandedCostWorksheetQuery $landedQuery,
        private readonly CreateShipment $create,
        private readonly RecordShipmentEvent $recordEvent,
        private readonly MarkConsignmentReceived $markReceived,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->listQuery->execute(
            status: $request->query('status'),
            purchaseOrderId: $request->query('purchaseOrderId'),
            limit: (int) $request->query('limit', 50),
            offset: (int) $request->query('offset', 0),
        ));
    }

    public function show(string $id): JsonResponse
    {
        return response()->json($this->getQuery->execute($id));
    }

    public function store(StoreShipmentRequest $request): JsonResponse
    {
        return response()->json($this->create->execute($request->validated()), 201);
    }

    public function recordEvent(string $id, RecordShipmentEventRequest $request): JsonResponse
    {
        return response()->json($this->recordEvent->execute($id, $request->validated()));
    }

    public function receive(string $id, string $cid, MarkConsignmentReceivedRequest $request): JsonResponse
    {
        return response()->json($this->markReceived->execute($id, $cid, $request->validated()));
    }

    public function landedCost(string $id, Request $request): JsonResponse
    {
        $basis = (string) $request->query('basis', 'quantity');

        return response()->json($this->landedQuery->execute($id, $basis));
    }
}
