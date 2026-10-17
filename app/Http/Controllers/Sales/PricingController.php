<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Http\Requests\Sales\CalculatePriceRequest;
use App\Http\Requests\Sales\UpsertPricingParametersRequest;
use App\Modules\Sales\Actions\CalculatePrice;
use App\Modules\Sales\Actions\UpsertPricingParameters;
use App\Modules\Sales\Queries\GetPricingParametersQuery;
use Illuminate\Http\JsonResponse;

final class PricingController
{
    public function __construct(
        private readonly GetPricingParametersQuery $getPricingParametersQuery,
        private readonly UpsertPricingParameters $upsertPricingParameters,
        private readonly CalculatePrice $calculatePrice,
    ) {
    }

    public function parameters(): JsonResponse
    {
        return response()->json($this->getPricingParametersQuery->execute());
    }

    public function updateParameters(UpsertPricingParametersRequest $request): JsonResponse
    {
        return response()->json($this->upsertPricingParameters->execute($request->validated()));
    }

    public function calculate(CalculatePriceRequest $request): JsonResponse
    {
        return response()->json($this->calculatePrice->execute($request->validated()));
    }
}
