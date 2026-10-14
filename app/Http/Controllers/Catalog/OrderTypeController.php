<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Http\Requests\Catalog\StoreOrderTypeRequest;
use App\Http\Requests\Catalog\UpdateOrderTypeRequest;
use App\Modules\Catalog\Actions\CreateOrderType;
use App\Modules\Catalog\Actions\UpdateOrderType;
use App\Modules\Catalog\Queries\ListOrderTypesQuery;
use Illuminate\Http\JsonResponse;

final class OrderTypeController
{
    public function __construct(
        private readonly ListOrderTypesQuery $listOrderTypesQuery,
        private readonly CreateOrderType $createOrderType,
        private readonly UpdateOrderType $updateOrderType,
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->listOrderTypesQuery->execute());
    }

    public function store(StoreOrderTypeRequest $request): JsonResponse
    {
        $orderType = $this->createOrderType->execute($request->validated());

        return response()->json($orderType, 201);
    }

    public function update(string $id, UpdateOrderTypeRequest $request): JsonResponse
    {
        $orderType = $this->updateOrderType->execute($id, $request->validated());

        return response()->json($orderType);
    }
}
