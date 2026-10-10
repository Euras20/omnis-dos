<?php

declare(strict_types=1);

namespace App\Http\Controllers\Procurement;

use App\Http\Requests\Procurement\StorePurchaseOrderRequest;
use App\Modules\Procurement\Actions\ApprovePurchaseOrder;
use App\Modules\Procurement\Actions\CreatePurchaseOrder;
use App\Modules\Procurement\Queries\ListPurchaseOrdersQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PurchaseOrderController
{
    public function __construct(
        private readonly ListPurchaseOrdersQuery $listPurchaseOrdersQuery,
        private readonly CreatePurchaseOrder $createPurchaseOrder,
        private readonly ApprovePurchaseOrder $approvePurchaseOrder,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->listPurchaseOrdersQuery->execute([
            'supplierId' => $request->query('supplierId'),
            'status' => $request->query('status'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'q' => $request->query('q'),
            'limit' => $request->query('limit'),
            'offset' => $request->query('offset'),
        ]));
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $po = $this->createPurchaseOrder->execute($request->validated());

        return response()->json($po, 201);
    }

    public function approve(string $id): JsonResponse
    {
        $result = $this->approvePurchaseOrder->execute($id);

        return response()->json($result);
    }
}
