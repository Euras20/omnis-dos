<?php

declare(strict_types=1);

namespace App\Http\Controllers\Procurement;

use App\Http\Requests\Procurement\RecordQcRequest;
use App\Http\Requests\Procurement\StoreGoodsReceiptRequest;
use App\Modules\Procurement\Actions\PostGoodsReceipt;
use App\Modules\Procurement\Actions\RecordQcResult;
use App\Modules\Procurement\Queries\ListPendingQcQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GoodsReceiptController
{
    public function __construct(
        private readonly ListPendingQcQuery $listPendingQcQuery,
        private readonly PostGoodsReceipt $postGoodsReceipt,
        private readonly RecordQcResult $recordQcResult,
    ) {
    }

    public function pendingQc(Request $request): JsonResponse
    {
        return response()->json($this->listPendingQcQuery->execute([
            'goodsReceiptId' => $request->query('goodsReceiptId'),
            'itemId' => $request->query('itemId'),
            'limit' => $request->query('limit'),
            'offset' => $request->query('offset'),
        ]));
    }

    public function store(StoreGoodsReceiptRequest $request): JsonResponse
    {
        $grn = $this->postGoodsReceipt->execute($request->validated());

        return response()->json($grn, 201);
    }

    public function qc(string $id, RecordQcRequest $request): JsonResponse
    {
        $result = $this->recordQcResult->execute($id, $request->validated());

        return response()->json($result);
    }
}
