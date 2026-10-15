<?php

declare(strict_types=1);

namespace App\Modules\Controls\Queries;

use App\Models\AppUser;
use App\Modules\Inventory\Queries\LowStockAlertQuery;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * System-wide in-app alerts for internal (staff) users.
 *
 * Currently surfaces the low-stock alert (items at or below 50% of reorder point).
 * Customer portal users (user_type = portal) never see these alerts.
 * The frontend notification bell / inbox and any internal page can consume this feed.
 */
final class SystemAlertsQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly LowStockAlertQuery $lowStockAlertQuery,
    ) {
    }

    /**
     * @return array{alerts: list<array<string, mixed>>, lowStockCount: int}
     */
    public function execute(): array
    {
        $actorId = $this->companyContext->userId();
        if ($actorId === null) {
            return ['alerts' => [], 'lowStockCount' => 0];
        }

        $user = AppUser::query()->find($actorId);
        // Portal users (customer, supplier, employee, driver) do not receive internal alerts.
        if ($user === null || $user->user_type === 'portal') {
            return ['alerts' => [], 'lowStockCount' => 0];
        }

        // Only users who can view stock see the low-stock alert.
        if (! $this->permissionChecker->can($actorId, 'stock.view')) {
            return ['alerts' => [], 'lowStockCount' => 0];
        }

        $lowStock = $this->lowStockAlertQuery->execute(['limit' => 50]);

        $alerts = [];
        foreach ($lowStock['items'] as $item) {
            $alerts[] = [
                'id' => 'low-stock-'.$item['itemId'],
                'type' => 'low_stock',
                'severity' => 'warning',
                'text' => sprintf(
                    'Low stock: %s (%s) is at %s of reorder point (%s available, threshold %s)',
                    $item['sku'],
                    $item['itemName'],
                    $item['percentOfReorder'].'%',
                    $item['qtyAvailable'],
                    $item['thresholdQty'],
                ),
                'link' => '/inventory/item/'.$item['sku'],
                'itemId' => $item['itemId'],
                'sku' => $item['sku'],
                'qtyAvailable' => $item['qtyAvailable'],
                'reorderPoint' => $item['reorderPoint'],
                'thresholdPercent' => $lowStock['thresholdPercent'],
                'read' => false,
            ];
        }

        return [
            'alerts' => $alerts,
            'lowStockCount' => count($alerts),
        ];
    }
}
