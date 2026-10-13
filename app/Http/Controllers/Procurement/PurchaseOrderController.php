<?php

declare(strict_types=1);

namespace App\Http\Controllers\Procurement;

use App\Http\Requests\Procurement\StorePurchaseOrderRequest;
use App\Modules\Procurement\Actions\ApprovePurchaseOrder;
use App\Modules\Procurement\Actions\CancelPurchaseOrder;
use App\Modules\Procurement\Actions\ClosePurchaseOrder;
use App\Modules\Procurement\Actions\CreatePurchaseOrder;
use App\Modules\Procurement\Queries\GetPurchaseOrderPdfQuery;
use App\Modules\Procurement\Queries\GetPurchaseOrderQuery;
use App\Modules\Procurement\Queries\ListPurchaseOrdersQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class PurchaseOrderController
{
    public function __construct(
        private readonly ListPurchaseOrdersQuery $listPurchaseOrdersQuery,
        private readonly GetPurchaseOrderQuery $getPurchaseOrderQuery,
        private readonly GetPurchaseOrderPdfQuery $getPurchaseOrderPdfQuery,
        private readonly CreatePurchaseOrder $createPurchaseOrder,
        private readonly ApprovePurchaseOrder $approvePurchaseOrder,
        private readonly CancelPurchaseOrder $cancelPurchaseOrder,
        private readonly ClosePurchaseOrder $closePurchaseOrder,
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

    public function show(string $id): JsonResponse
    {
        return response()->json($this->getPurchaseOrderQuery->execute($id));
    }

    public function pdf(string $id): Response
    {
        $result = $this->getPurchaseOrderPdfQuery->execute($id);

        return response($result['bytes'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$result['fileName'].'"',
            'X-Document-File-Id' => $result['documentFileId'],
        ]);
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

    public function cancel(string $id): JsonResponse
    {
        $result = $this->cancelPurchaseOrder->execute($id);

        return response()->json($result);
    }

    public function close(string $id): JsonResponse
    {
        $result = $this->closePurchaseOrder->execute($id);

        return response()->json($result);
    }
}
