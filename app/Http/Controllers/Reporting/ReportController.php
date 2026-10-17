<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reporting;

use App\Http\Requests\Reporting\SaveReportRequest;
use App\Http\Requests\Reporting\ToggleFavouriteRequest;
use App\Modules\Reporting\Actions\DeleteSavedReport;
use App\Modules\Reporting\Actions\SaveReport;
use App\Modules\Reporting\Actions\ToggleFavourite;
use App\Modules\Reporting\Queries\GeneralLedgerQuery;
use App\Modules\Reporting\Queries\ListFavouritesQuery;
use App\Modules\Reporting\Queries\ListSavedReportsQuery;
use App\Modules\Reporting\Queries\ReportCatalogueQuery;
use App\Modules\Reporting\Queries\ReportStubQuery;
use App\Modules\Reporting\Queries\TrialBalanceQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReportController
{
    public function __construct(
        private readonly ReportCatalogueQuery $catalogue,
        private readonly ListSavedReportsQuery $saved,
        private readonly ListFavouritesQuery $favourites,
        private readonly SaveReport $saveReport,
        private readonly DeleteSavedReport $deleteSaved,
        private readonly ToggleFavourite $toggleFavourite,
        private readonly ReportStubQuery $stubs,
    ) {
    }

    /**
     * GET /api/v1/reports/trial-balance
     */
    public function trialBalance(Request $request, TrialBalanceQuery $query): JsonResponse
    {
        return response()->json($query->execute([
            'asOf' => $request->query('asOf') ?? $request->query('asof'),
            'currency' => $request->query('currency') ?? $request->query('cur'),
        ]));
    }

    /**
     * GET /api/v1/reports/general-ledger
     */
    public function generalLedger(Request $request, GeneralLedgerQuery $query): JsonResponse
    {
        return response()->json($query->execute([
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'account' => $request->query('account') ?? $request->query('acct'),
            'currency' => $request->query('currency') ?? $request->query('cur'),
        ]));
    }

    /**
     * GET /api/v1/reports — catalogue
     */
    public function index(): JsonResponse
    {
        return response()->json($this->catalogue->execute());
    }

    /**
     * GET /api/v1/reports/saved
     */
    public function saved(): JsonResponse
    {
        return response()->json($this->saved->execute());
    }

    /**
     * POST /api/v1/reports/saved
     */
    public function storeSaved(SaveReportRequest $request): JsonResponse
    {
        return response()->json($this->saveReport->execute($request->validated()), 201);
    }

    /**
     * DELETE /api/v1/reports/saved/{id}
     */
    public function destroySaved(string $id): JsonResponse
    {
        $this->deleteSaved->execute($id);

        return response()->json(['deleted' => true]);
    }

    /**
     * GET /api/v1/reports/favourites
     */
    public function favourites(): JsonResponse
    {
        return response()->json($this->favourites->execute());
    }

    /**
     * POST /api/v1/reports/favourites
     */
    public function toggleFavourite(ToggleFavouriteRequest $request): JsonResponse
    {
        return response()->json($this->toggleFavourite->execute($request->validated()));
    }

    /**
     * GET /api/v1/reports/stock
     */
    public function stock(): JsonResponse
    {
        return response()->json($this->stubs->stock());
    }

    /**
     * GET /api/v1/reports/logistics
     */
    public function logistics(): JsonResponse
    {
        return response()->json($this->stubs->logistics());
    }

    /**
     * GET /api/v1/reports/management
     */
    public function management(): JsonResponse
    {
        return response()->json($this->stubs->management());
    }

    /**
     * GET /api/v1/reports/performance
     */
    public function performance(Request $request): JsonResponse
    {
        return response()->json($this->stubs->performance($request->query('view')));
    }

    /**
     * GET /api/v1/reports/{key} — generic stub
     */
    public function show(string $key): JsonResponse
    {
        return response()->json($this->stubs->generic($key));
    }
}
