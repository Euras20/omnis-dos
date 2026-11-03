<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fulfillment;

use App\Http\Requests\Fulfillment\InspectGoodsReturnRequest;
use App\Http\Requests\Fulfillment\StoreGoodsReturnRequest;
use App\Modules\Fulfillment\Actions\CreateGoodsReturn;
use App\Modules\Fulfillment\Actions\DispatchSupplierReturn;
use App\Modules\Fulfillment\Actions\InspectGoodsReturn;
use App\Modules\Fulfillment\Queries\GetReturnQuery;
use App\Modules\Fulfillment\Queries\ListReturnableLinesQuery;
use App\Modules\Fulfillment\Queries\ListReturnsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GoodsReturnController
{
    public function __construct(
        private readonly ListReturnsQuery $listQuery,
        private readonly GetReturnQuery $getQuery,
        private readonly ListReturnableLinesQuery $returnableQuery,
        private readonly CreateGoodsReturn $create,
        private readonly InspectGoodsReturn $inspect,
        private readonly DispatchSupplierReturn $dispatch,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->listQuery->execute(
            status: $request->query('status'),
            kind: $request->query('kind'),
            limit: (int) $request->query('limit', 50),
            offset: (int) $request->query('offset', 0),
        ));
    }

    public function returnable(): JsonResponse
    {
        return response()->json($this->returnableQuery->execute());
    }

    public function show(string $id): JsonResponse
    {
        return response()->json($this->getQuery->execute($id));
    }

    public function store(StoreGoodsReturnRequest $request): JsonResponse
    {
        return response()->json($this->create->execute($request->validated()), 201);
    }

    public function inspect(string $id, InspectGoodsReturnRequest $request): JsonResponse
    {
        return response()->json($this->inspect->execute($id, $request->validated()));
    }

    public function dispatch(string $id): JsonResponse
    {
        return response()->json($this->dispatch->execute($id));
    }
}
