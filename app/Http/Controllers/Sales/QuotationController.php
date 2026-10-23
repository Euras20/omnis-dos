<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Http\Requests\Sales\ChangeQuotationStatusRequest;
use App\Http\Requests\Sales\ConvertQuotationRequest;
use App\Http\Requests\Sales\StoreQuotationRequest;
use App\Http\Requests\Sales\UpdateQuotationRequest;
use App\Modules\Reporting\Queries\DocumentPrintQuery;
use App\Modules\Sales\Actions\ChangeQuotationStatus;
use App\Modules\Sales\Actions\ConvertQuotation;
use App\Modules\Sales\Actions\CreateQuotation;
use App\Modules\Sales\Actions\SendQuotation;
use App\Modules\Sales\Actions\UpdateQuotation;
use App\Modules\Sales\Queries\GetQuotationQuery;
use App\Modules\Sales\Queries\ListQuotationsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class QuotationController
{
    public function __construct(
        private readonly ListQuotationsQuery $listQuotationsQuery,
        private readonly GetQuotationQuery $getQuotationQuery,
        private readonly CreateQuotation $createQuotation,
        private readonly UpdateQuotation $updateQuotation,
        private readonly ChangeQuotationStatus $changeQuotationStatus,
        private readonly ConvertQuotation $convertQuotation,
        private readonly SendQuotation $sendQuotation,
        private readonly DocumentPrintQuery $printQuery,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->listQuotationsQuery->execute([
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
        return response()->json($this->getQuotationQuery->execute($id));
    }

    public function store(StoreQuotationRequest $request): JsonResponse
    {
        $quotation = $this->createQuotation->execute($request->validated());

        return response()->json($quotation, 201);
    }

    public function update(string $id, UpdateQuotationRequest $request): JsonResponse
    {
        $quotation = $this->updateQuotation->execute($id, $request->validated());

        return response()->json($quotation);
    }

    public function status(string $id, ChangeQuotationStatusRequest $request): JsonResponse
    {
        $result = $this->changeQuotationStatus->execute($id, $request->validated());

        return response()->json($result);
    }

    public function convert(string $id, ConvertQuotationRequest $request): JsonResponse
    {
        $result = $this->convertQuotation->execute($id, $request->validated());

        return response()->json($result, 201);
    }

    public function send(string $id, Request $request): JsonResponse
    {
        $data = $request->validate([
            'to' => ['nullable', 'email', 'max:255'],
        ]);

        return response()->json($this->sendQuotation->execute($id, $data));
    }

    public function print(string $id): JsonResponse
    {
        return response()->json($this->printQuery->quotation($id));
    }
}
