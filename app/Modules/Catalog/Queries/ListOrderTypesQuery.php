<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Models\OrderType;
use App\Modules\Catalog\Actions\CreateOrderType;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * GET /api/v1/order-types
 */
final class ListOrderTypesQuery
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
        $actorId = $this->companyContext->userId();
        if (! $this->permissionChecker->can($actorId, 'sales.orders.view')
            && ! $this->permissionChecker->can($actorId, 'items.view')) {
            $this->permissionChecker->assertCan($actorId, 'sales.orders.view');
        }

        $items = OrderType::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->map(fn (OrderType $o) => CreateOrderType::toArray($o))
            ->all();

        return ['items' => $items];
    }
}
