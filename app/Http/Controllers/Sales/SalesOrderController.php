<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Http\Requests\Sales\StoreSalesOrderRequest;
use App\Modules\Sales\Actions\ConfirmSalesOrder;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\Queries\GetSalesOrderQuery;
use App\Modules\Sales\Queries\ListSalesOrdersQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SalesOrderController
{
    public function __construct(
        private readonly ListSalesOrdersQuery $listSalesOrdersQuery,
        private readonly GetSalesOrderQuery $getSalesOrderQuery,
        private readonly CreateSalesOrder $createSalesOrder,
        private readonly ConfirmSalesOrder $confirmSalesOrder,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->listSalesOrdersQuery->execute([
            'customerId' => $request->query('customerId'),
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
        return response()->json($this->getSalesOrderQuery->execute($id));
    }

    public function store(StoreSalesOrderRequest $request): JsonResponse
    {
        $order = $this->createSalesOrder->execute($request->validated());

        return response()->json($order, 201);
    }

    public function confirm(string $id): JsonResponse
    {
        $result = $this->confirmSalesOrder->execute($id);

        return response()->json($result);
    }
}
