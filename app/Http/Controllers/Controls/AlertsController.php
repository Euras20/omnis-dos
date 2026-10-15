<?php

declare(strict_types=1);

namespace App\Http\Controllers\Controls;

use App\Modules\Controls\Queries\SystemAlertsQuery;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/alerts — in-system alerts for internal users (low-stock and future system notices).
 * Customer portal users receive an empty list.
 */
final class AlertsController
{
    public function __construct(
        private readonly SystemAlertsQuery $systemAlertsQuery,
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->systemAlertsQuery->execute());
    }
}
