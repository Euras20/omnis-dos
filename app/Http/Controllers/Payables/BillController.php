<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payables;

use App\Http\Requests\Payables\StoreBillRequest;
use App\Modules\Payables\Actions\PostBill;
use App\Modules\Payables\Queries\GetBillQuery;
use App\Modules\Payables\Queries\ListBillsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BillController
{
    public function __construct(
        private readonly ListBillsQuery $listBillsQuery,
        private readonly GetBillQuery $getBillQuery,
        private readonly PostBill $postBill,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->listBillsQuery->execute([
            'supplierId' => $request->query('supplierId'),
            'status' => $request->query('status'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'cutoff' => $request->query('cutoff'),
            'limit' => $request->query('limit'),
            'offset' => $request->query('offset'),
        ]));
    }

    public function show(string $id, Request $request): JsonResponse
    {
        return response()->json($this->getBillQuery->execute($id, [
            'cutoff' => $request->query('cutoff'),
        ]));
    }

    public function store(StoreBillRequest $request): JsonResponse
    {
        $bill = $this->postBill->execute($request->validated());

        return response()->json($bill, 201);
    }
}
