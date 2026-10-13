<?php

declare(strict_types=1);

namespace App\Http\Controllers\Procurement;

use App\Modules\Procurement\Actions\AwardRfq;
use App\Modules\Procurement\Actions\CreateRfq;
use App\Modules\Procurement\Actions\SubmitRfqBid;
use App\Modules\Procurement\Queries\ListRfqsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RfqController
{
    public function __construct(
        private readonly ListRfqsQuery $listRfqsQuery,
        private readonly CreateRfq $createRfq,
        private readonly SubmitRfqBid $submitRfqBid,
        private readonly AwardRfq $awardRfq,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->listRfqsQuery->execute([
            'status' => $request->query('status'),
            'limit' => $request->query('limit'),
            'offset' => $request->query('offset'),
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->createRfq->execute($request->all()), 201);
    }

    public function storeBid(string $id, Request $request): JsonResponse
    {
        return response()->json($this->submitRfqBid->execute($id, $request->all()), 201);
    }

    public function award(string $id, Request $request): JsonResponse
    {
        return response()->json($this->awardRfq->execute($id, $request->all()));
    }
}
