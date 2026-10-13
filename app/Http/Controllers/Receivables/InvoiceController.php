<?php

declare(strict_types=1);

namespace App\Http\Controllers\Receivables;

use App\Http\Requests\Receivables\StoreInvoiceRequest;
use App\Modules\Receivables\Actions\PostCreditNote;
use App\Modules\Receivables\Actions\PostInvoice;
use App\Modules\Receivables\Queries\GetInvoiceQuery;
use App\Modules\Receivables\Queries\ListInvoicesQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InvoiceController
{
    public function __construct(
        private readonly ListInvoicesQuery $listInvoicesQuery,
        private readonly GetInvoiceQuery $getInvoiceQuery,
        private readonly PostInvoice $postInvoice,
        private readonly PostCreditNote $postCreditNote,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->listInvoicesQuery->execute([
            'customerId' => $request->query('customerId'),
            'salesOrderId' => $request->query('salesOrderId'),
            'status' => $request->query('status'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'cutoff' => $request->query('cutoff'),
            'q' => $request->query('q'),
            'limit' => $request->query('limit'),
            'offset' => $request->query('offset'),
        ]));
    }

    public function show(string $id): JsonResponse
    {
        return response()->json($this->getInvoiceQuery->execute($id));
    }

    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        $invoice = $this->postInvoice->execute($request->validated());

        return response()->json($invoice, 201);
    }

    public function storeCreditNote(string $id, Request $request): JsonResponse
    {
        $credit = $this->postCreditNote->execute($id, $request->all());

        return response()->json($credit, 201);
    }
}
