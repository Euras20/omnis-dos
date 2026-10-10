<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Http\Requests\Catalog\StoreLocationRequest;
use App\Modules\Catalog\Actions\CreateLocation;
use App\Modules\Catalog\Actions\DeleteLocation;
use App\Modules\Catalog\Queries\ListLocationsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LocationController
{
    public function __construct(
        private readonly ListLocationsQuery $listLocationsQuery,
        private readonly CreateLocation $createLocation,
        private readonly DeleteLocation $deleteLocation,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->listLocationsQuery->execute([
            'status' => $request->query('status'),
            'type' => $request->query('type'),
        ]));
    }

    public function store(StoreLocationRequest $request): JsonResponse
    {
        $location = $this->createLocation->execute($request->validated());

        return response()->json($location, 201);
    }

    public function destroy(string $id): JsonResponse
    {
        return response()->json($this->deleteLocation->execute($id));
    }
}
