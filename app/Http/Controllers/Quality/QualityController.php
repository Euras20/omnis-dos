<?php

declare(strict_types=1);

namespace App\Http\Controllers\Quality;

use App\Http\Requests\Quality\CreateNonconformanceRequest;
use App\Http\Requests\Quality\RejectQuarantineRequest;
use App\Http\Requests\Quality\SendToCorrectiveActionRequest;
use App\Modules\Quality\Actions\CloseNonconformance;
use App\Modules\Quality\Actions\CreateNonconformance;
use App\Modules\Quality\Actions\RejectQuarantine;
use App\Modules\Quality\Actions\SendToCorrectiveAction;
use App\Modules\Quality\Queries\ListCorrectiveActionsQuery;
use App\Modules\Quality\Queries\ListNonconformancesQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class QualityController
{
    public function __construct(
        private readonly ListNonconformancesQuery $listNonconformances,
        private readonly ListCorrectiveActionsQuery $listCorrectiveActions,
        private readonly CreateNonconformance $createNonconformance,
        private readonly SendToCorrectiveAction $sendToCorrectiveAction,
        private readonly CloseNonconformance $closeNonconformance,
        private readonly RejectQuarantine $rejectQuarantine,
    ) {
    }

    /**
     * GET /api/v1/quality/nonconformances
     */
    public function nonconformances(Request $request): JsonResponse
    {
        $result = $this->listNonconformances->execute(
            status: $request->query('status'),
            limit: (int) $request->query('limit', 50),
            offset: (int) $request->query('offset', 0),
        );

        return response()->json($result);
    }

    /**
     * GET /api/v1/quality/corrective-actions
     */
    public function correctiveActions(Request $request): JsonResponse
    {
        $result = $this->listCorrectiveActions->execute(
            status: $request->query('status'),
            limit: (int) $request->query('limit', 50),
            offset: (int) $request->query('offset', 0),
        );

        return response()->json($result);
    }

    /**
     * POST /api/v1/quality/nonconformances
     */
    public function storeNonconformance(CreateNonconformanceRequest $request): JsonResponse
    {
        $result = $this->createNonconformance->execute($request->validated());

        return response()->json($result, 201);
    }

    /**
     * POST /api/v1/quality/nonconformances/{id}/send-to-corrective-action
     */
    public function sendToCorrectiveAction(string $id, SendToCorrectiveActionRequest $request): JsonResponse
    {
        $result = $this->sendToCorrectiveAction->execute($id, $request->validated());

        return response()->json($result, 201);
    }

    /**
     * POST /api/v1/quality/nonconformances/{id}/close
     */
    public function closeNonconformance(string $id, Request $request): JsonResponse
    {
        $result = $this->closeNonconformance->execute($id, [
            'note' => $request->input('note'),
        ]);

        return response()->json($result);
    }

    /**
     * POST /api/v1/quality/quarantine/reject
     *
     * Reject form (why, when, action) creates a nonconformance.
     */
    public function rejectQuarantine(RejectQuarantineRequest $request): JsonResponse
    {
        $result = $this->rejectQuarantine->execute($request->validated());

        return response()->json($result, 201);
    }
}
