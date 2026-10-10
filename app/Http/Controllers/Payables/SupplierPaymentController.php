<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payables;

use App\Http\Requests\Payables\StoreSupplierPaymentRequest;
use App\Modules\Payables\Actions\CancelSupplierPayment;
use App\Modules\Payables\Actions\ConfirmSupplierPayment;
use App\Modules\Payables\Actions\PrepareSupplierPayment;
use App\Modules\Payables\Queries\ListSupplierPaymentsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SupplierPaymentController
{
    public function __construct(
        private readonly ListSupplierPaymentsQuery $listSupplierPaymentsQuery,
        private readonly PrepareSupplierPayment $prepareSupplierPayment,
        private readonly ConfirmSupplierPayment $confirmSupplierPayment,
        private readonly CancelSupplierPayment $cancelSupplierPayment,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->listSupplierPaymentsQuery->execute([
            'supplierId' => $request->query('supplierId'),
            'status' => $request->query('status'),
            'limit' => $request->query('limit'),
            'offset' => $request->query('offset'),
        ]));
    }

    public function store(StoreSupplierPaymentRequest $request): JsonResponse
    {
        $payment = $this->prepareSupplierPayment->execute($request->validated());

        return response()->json($payment, 201);
    }

    public function confirm(string $id, Request $request): JsonResponse
    {
        $payment = $this->confirmSupplierPayment->execute($id, $request->all());

        return response()->json($payment);
    }

    public function cancel(string $id): JsonResponse
    {
        $payment = $this->cancelSupplierPayment->execute($id);

        return response()->json($payment);
    }
}
