<?php

declare(strict_types=1);

namespace App\Http\Controllers\Receivables;

use App\Http\Requests\Receivables\StoreReceiptRequest;
use App\Modules\Receivables\Actions\ApplyReceipt;
use App\Modules\Receivables\Actions\PostReceipt;
use App\Modules\Receivables\Actions\PostRefund;
use App\Modules\Receivables\Queries\ListReceiptsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReceiptController
{
    public function __construct(
        private readonly ListReceiptsQuery $listReceiptsQuery,
        private readonly PostReceipt $postReceipt,
        private readonly ApplyReceipt $applyReceipt,
        private readonly PostRefund $postRefund,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->listReceiptsQuery->execute([
            'customerId' => $request->query('customerId'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'cutoff' => $request->query('cutoff'),
            'limit' => $request->query('limit'),
            'offset' => $request->query('offset'),
        ]));
    }

    public function store(StoreReceiptRequest $request): JsonResponse
    {
        $receipt = $this->postReceipt->execute($request->validated());

        return response()->json($receipt, 201);
    }

    public function apply(string $id, Request $request): JsonResponse
    {
        $result = $this->applyReceipt->execute($id, $request->all());

        return response()->json($result);
    }

    public function refund(string $id, Request $request): JsonResponse
    {
        $refund = $this->postRefund->execute($id, $request->all());

        return response()->json($refund, 201);
    }
}
