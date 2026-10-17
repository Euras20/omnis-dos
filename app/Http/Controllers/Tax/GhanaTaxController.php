<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tax;

use App\Http\Requests\Tax\UpsertGhanaTaxPackageRequest;
use App\Modules\Tax\Actions\UpsertGhanaTaxPackage;
use App\Modules\Tax\Queries\GetGhanaTaxPackageQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GhanaTaxController
{
    public function show(Request $request, GetGhanaTaxPackageQuery $query): JsonResponse
    {
        return response()->json($query->execute($request->query('asOf')));
    }

    public function store(UpsertGhanaTaxPackageRequest $request, UpsertGhanaTaxPackage $action): JsonResponse
    {
        return response()->json($action->execute($request->validated()), 201);
    }
}
