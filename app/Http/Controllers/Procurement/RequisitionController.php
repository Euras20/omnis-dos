<?php

declare(strict_types=1);

namespace App\Http\Controllers\Procurement;

use App\Modules\Procurement\Actions\ApproveRequisition;
use App\Modules\Procurement\Actions\ConvertRequisition;
use App\Modules\Procurement\Actions\CreateRequisition;
use App\Modules\Procurement\Actions\RejectRequisition;
use App\Modules\Procurement\Queries\ListRequisitionsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RequisitionController
{
    public function __construct(
        private readonly ListRequisitionsQuery $listRequisitionsQuery,
        private readonly CreateRequisition $createRequisition,
        private readonly ApproveRequisition $approveRequisition,
        private readonly RejectRequisition $rejectRequisition,
        private readonly ConvertRequisition $convertRequisition,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->listRequisitionsQuery->execute([
            'status' => $request->query('status'),
            'limit' => $request->query('limit'),
            'offset' => $request->query('offset'),
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $result = $this->createRequisition->execute($request->all());

        return response()->json($result, 201);
    }

    public function approve(string $id): JsonResponse
    {
        return response()->json($this->approveRequisition->execute($id));
    }

    public function reject(string $id, Request $request): JsonResponse
    {
        return response()->json($this->rejectRequisition->execute($id, $request->all()));
    }

    public function convert(string $id, Request $request): JsonResponse
    {
        return response()->json($this->convertRequisition->execute($id, $request->all()));
    }
}
