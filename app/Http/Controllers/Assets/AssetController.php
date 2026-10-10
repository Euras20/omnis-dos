<?php

declare(strict_types=1);

namespace App\Http\Controllers\Assets;

use App\Http\Controllers\Controller;
use App\Models\AssetClass;
use App\Models\FixedAsset;
use App\Modules\Assets\Actions\CapitaliseAsset;
use App\Modules\Assets\Actions\PostDepreciationRun;
use App\Shared\Company\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AssetController extends Controller
{
    public function index(Request $request, CompanyContext $companyContext): JsonResponse
    {
        $asAt = (string) $request->query('asAt', date('Y-m-d'));
        $companyId = $companyContext->companyId();

        $rows = FixedAsset::query()
            ->where('company_id', $companyId)
            ->orderBy('asset_no')
            ->get()
            ->map(function (FixedAsset $a) use ($asAt) {
                $accum = (int) DB::table('depreciation_line')
                    ->where('fixed_asset_id', $a->id)
                    ->sum('charge_minor');

                return [
                    'id' => $a->id,
                    'assetNo' => $a->asset_no,
                    'name' => $a->name,
                    'assetClassId' => $a->asset_class_id,
                    'acquisitionDate' => $a->acquisition_date?->format('Y-m-d'),
                    'costMinor' => (int) $a->cost_minor,
                    'accumulatedDepreciationMinor' => $accum,
                    'netBookValueMinor' => (int) $a->cost_minor - $accum,
                    'status' => $a->status,
                    'asAt' => $asAt,
                ];
            });

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request, CapitaliseAsset $action): JsonResponse
    {
        return response()->json($action->execute($request->all()), 201);
    }

    public function classes(CompanyContext $companyContext): JsonResponse
    {
        $rows = AssetClass::query()
            ->where('company_id', $companyContext->companyId())
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->map(fn (AssetClass $c) => [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'method' => $c->method,
                'usefulLifeMonths' => (int) $c->useful_life_months,
            ]);

        return response()->json(['data' => $rows]);
    }

    public function postDepreciation(string $month, PostDepreciationRun $action): JsonResponse
    {
        return response()->json($action->execute($month), 201);
    }
}
