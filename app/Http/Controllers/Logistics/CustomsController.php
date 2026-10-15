<?php

declare(strict_types=1);

namespace App\Http\Controllers\Logistics;

use App\Http\Requests\Logistics\ReleaseCustomsRequest;
use App\Http\Requests\Logistics\StoreCustomsDeclarationRequest;
use App\Modules\Logistics\Actions\CreateCustomsDeclaration;
use App\Modules\Logistics\Actions\ReleaseCustoms;
use App\Modules\Logistics\Queries\GetCustomsQuery;
use App\Modules\Logistics\Queries\ListCustomsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CustomsController
{
    public function __construct(
        private readonly ListCustomsQuery $listQuery,
        private readonly GetCustomsQuery $getQuery,
        private readonly CreateCustomsDeclaration $create,
        private readonly ReleaseCustoms $release,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->listQuery->execute(
            status: $request->query('status'),
            limit: (int) $request->query('limit', 50),
            offset: (int) $request->query('offset', 0),
        ));
    }

    public function show(string $id): JsonResponse
    {
        return response()->json($this->getQuery->execute($id));
    }

    public function store(StoreCustomsDeclarationRequest $request): JsonResponse
    {
        return response()->json($this->create->execute($request->validated()), 201);
    }

    public function release(string $id, ReleaseCustomsRequest $request): JsonResponse
    {
        return response()->json($this->release->execute($id, $request->validated()));
    }
}
