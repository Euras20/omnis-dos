<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Http\Requests\Catalog\StoreItemRequest;
use App\Http\Requests\Catalog\UpdateItemRequest;
use App\Modules\Catalog\Actions\CreateItem;
use App\Modules\Catalog\Actions\UpdateItem;
use App\Modules\Catalog\Queries\ListItemsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ItemController
{
    public function __construct(
        private readonly ListItemsQuery $listItemsQuery,
        private readonly CreateItem $createItem,
        private readonly UpdateItem $updateItem,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->listItemsQuery->execute([
            'q' => $request->query('q'),
            'type' => $request->query('type'),
            'category' => $request->query('category'),
            'status' => $request->query('status'),
            'limit' => $request->query('limit'),
            'offset' => $request->query('offset'),
        ]));
    }

    public function store(StoreItemRequest $request): JsonResponse
    {
        $item = $this->createItem->execute($request->validated());

        return response()->json($item, 201);
    }

    public function update(string $id, UpdateItemRequest $request): JsonResponse
    {
        $item = $this->updateItem->execute($id, $request->validated());

        return response()->json($item);
    }
}
