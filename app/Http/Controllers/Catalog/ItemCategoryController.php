<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Http\Requests\Catalog\StoreItemCategoryRequest;
use App\Modules\Catalog\Actions\CreateItemCategory;
use App\Modules\Catalog\Queries\ListItemCategoriesQuery;
use Illuminate\Http\JsonResponse;

final class ItemCategoryController
{
    public function __construct(
        private readonly ListItemCategoriesQuery $listItemCategoriesQuery,
        private readonly CreateItemCategory $createItemCategory,
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->listItemCategoriesQuery->execute());
    }

    public function store(StoreItemCategoryRequest $request): JsonResponse
    {
        $category = $this->createItemCategory->execute($request->validated());

        return response()->json($category, 201);
    }
}
