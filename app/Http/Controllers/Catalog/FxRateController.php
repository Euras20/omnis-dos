<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Http\Requests\Catalog\FetchXeRatesRequest;
use App\Http\Requests\Catalog\UpsertFxRatesRequest;
use App\Modules\Catalog\Actions\FetchXeExchangeRates;
use App\Modules\Catalog\Actions\UpsertExchangeRates;
use App\Modules\Catalog\Queries\GetExchangeRatesQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FxRateController
{
    public function __construct(
        private readonly GetExchangeRatesQuery $getExchangeRatesQuery,
        private readonly UpsertExchangeRates $upsertExchangeRates,
        private readonly FetchXeExchangeRates $fetchXeExchangeRates,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->getExchangeRatesQuery->execute([
            'date' => $request->query('date'),
            'currency' => $request->query('currency'),
        ]));
    }

    public function upsert(string $date, UpsertFxRatesRequest $request): JsonResponse
    {
        $result = $this->upsertExchangeRates->execute($date, $request->validated());

        return response()->json($result);
    }

    /**
     * POST /api/v1/fx/rates/fetch-xe
     *
     * Fetches mid-market rates from XE.com (or a compatible mid-market source)
     * and stores them with a 3% selling markup for the given date.
     */
    public function fetchXe(FetchXeRatesRequest $request): JsonResponse
    {
        $result = $this->fetchXeExchangeRates->execute($request->validated());

        return response()->json($result);
    }
}
