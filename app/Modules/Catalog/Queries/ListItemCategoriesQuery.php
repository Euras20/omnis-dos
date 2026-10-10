<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Models\ItemCategory;
use App\Modules\Catalog\Actions\CreateItemCategory;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * GET /api/v1/item-categories
 */
final class ListItemCategoriesQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function execute(): array
    {
        $this->permissionChecker->assertCan($this->companyContext->userId(), 'items.view');

        $items = ItemCategory::query()
            ->orderBy('code')
            ->get()
            ->map(fn (ItemCategory $c) => CreateItemCategory::toArray($c))
            ->all();

        return ['items' => $items];
    }
}
