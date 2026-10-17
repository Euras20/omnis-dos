<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payroll;

use App\Http\Requests\Payroll\RejectLeaveRequest;
use App\Http\Requests\Payroll\RequestLeaveRequest;
use App\Modules\Payroll\Actions\ApproveLeave;
use App\Modules\Payroll\Actions\RejectLeave;
use App\Modules\Payroll\Actions\RequestLeave;
use App\Modules\Payroll\Queries\ListLeaveRequestsQuery;
use App\Modules\Payroll\Queries\MyLeaveQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LeaveController
{
    public function __construct(
        private readonly MyLeaveQuery $myLeave,
        private readonly ListLeaveRequestsQuery $listRequests,
        private readonly RequestLeave $requestLeave,
        private readonly ApproveLeave $approveLeave,
        private readonly RejectLeave $rejectLeave,
    ) {
    }

    /**
     * GET /api/v1/leave/me
     */
    public function me(): JsonResponse
    {
        return response()->json($this->myLeave->execute());
    }

    /**
     * GET /api/v1/leave
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->listRequests->execute(
            status: $request->query('status'),
            limit: (int) $request->query('limit', 50),
            offset: (int) $request->query('offset', 0),
        );

        return response()->json($result);
    }

    /**
     * POST /api/v1/leave
     */
    public function store(RequestLeaveRequest $request): JsonResponse
    {
        $result = $this->requestLeave->execute($request->validated());

        return response()->json($result, 201);
    }

    /**
     * POST /api/v1/leave/{id}/approve
     */
    public function approve(string $id): JsonResponse
    {
        return response()->json($this->approveLeave->execute($id));
    }

    /**
     * POST /api/v1/leave/{id}/reject
     */
    public function reject(string $id, RejectLeaveRequest $request): JsonResponse
    {
        return response()->json($this->rejectLeave->execute($id, $request->validated()));
    }
}
