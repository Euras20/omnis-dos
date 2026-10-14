<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Location;
use App\Models\Party;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Concurrency\RowVersion;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * PATCH /api/v1/items/{id}
 */
final class UpdateItem
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function execute(string $id, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'items.manage');
        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($id, $data, $actorId, $companyId) {
            $item = Item::query()
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($item === null) {
                throw new NotFoundException('Item not found.');
            }

            RowVersion::assertMatches($item, $data['rowVersion'] ?? null);

            if (array_key_exists('sku', $data) && $data['sku'] !== null) {
                $sku = strtoupper(trim((string) $data['sku']));
                if ($sku === '') {
                    throw new BusinessRuleException('sku cannot be empty.');
                }
                $dup = Item::query()
                    ->where('company_id', $companyId)
                    ->where('sku', $sku)
                    ->where('id', '!=', $item->id)
                    ->exists();
                if ($dup) {
                    throw new ConflictException("An item with SKU '{$sku}' already exists.");
                }
                $item->sku = $sku;
            }

            if (array_key_exists('name', $data) && $data['name'] !== null) {
                $name = trim((string) $data['name']);
                if ($name === '') {
                    throw new BusinessRuleException('name cannot be empty.');
                }
                $item->name = $name;
            }

            if (array_key_exists('itemType', $data) || array_key_exists('type', $data)) {
                $item->item_type = trim((string) ($data['itemType'] ?? $data['type'] ?? $item->item_type));
            }

            if (array_key_exists('categoryCode', $data)) {
                $categoryCode = $data['categoryCode'];
                if ($categoryCode === null || $categoryCode === '') {
                    $item->category_code = null;
                } else {
                    $categoryCode = strtoupper(trim((string) $categoryCode));
                    $exists = ItemCategory::query()
                        ->where('company_id', $companyId)
                        ->where('code', $categoryCode)
                        ->where('is_active', true)
                        ->exists();
                    if (! $exists) {
                        throw new BusinessRuleException("Item category '{$categoryCode}' was not found or is inactive.");
                    }
                    $item->category_code = $categoryCode;
                }
            }

            if (array_key_exists('unit', $data) && $data['unit'] !== null) {
                $item->unit = (string) $data['unit'];
            }
            if (array_key_exists('listPriceMinor', $data) || array_key_exists('priceMinor', $data)) {
                $item->list_price_minor = (int) ($data['listPriceMinor'] ?? $data['priceMinor'] ?? 0);
            }
            if (array_key_exists('standardCostMinor', $data) || array_key_exists('costMinor', $data)) {
                $item->standard_cost_minor = (int) ($data['standardCostMinor'] ?? $data['costMinor'] ?? 0);
            }
            if (array_key_exists('buyPriceMinor', $data)) {
                $item->buy_price_minor = $data['buyPriceMinor'] !== null ? (int) $data['buyPriceMinor'] : null;
            }
            if (array_key_exists('weightKg', $data) || array_key_exists('weight', $data)) {
                $item->weight_kg = $data['weightKg'] ?? $data['weight'] ?? null;
            }
            if (array_key_exists('dimensions', $data)) {
                $item->dimensions = $data['dimensions'];
            }

            if (array_key_exists('defaultLocationId', $data)) {
                $defaultLocationId = $data['defaultLocationId'];
                if ($defaultLocationId === null || $defaultLocationId === '') {
                    $item->default_location_id = null;
                } else {
                    $loc = Location::query()
                        ->where('company_id', $companyId)
                        ->where('id', $defaultLocationId)
                        ->where('is_active', true)
                        ->first();
                    if ($loc === null) {
                        throw new BusinessRuleException('defaultLocationId was not found or is inactive.');
                    }
                    $item->default_location_id = $defaultLocationId;
                }
            }

            if (array_key_exists('defaultSupplierId', $data)) {
                $defaultSupplierId = $data['defaultSupplierId'];
                if ($defaultSupplierId === null || $defaultSupplierId === '') {
                    $item->default_supplier_id = null;
                } else {
                    $supplier = Party::query()
                        ->where('company_id', $companyId)
                        ->where('id', $defaultSupplierId)
                        ->where('party_type', 'supplier')
                        ->where('is_active', true)
                        ->first();
                    if ($supplier === null) {
                        throw new BusinessRuleException('defaultSupplierId must be an active supplier.');
                    }
                    $item->default_supplier_id = $defaultSupplierId;
                }
            }

            if (array_key_exists('inventoryPolicy', $data) || array_key_exists('policy', $data)) {
                $item->inventory_policy = (string) ($data['inventoryPolicy'] ?? $data['policy'] ?? $item->inventory_policy);
            }
            if (array_key_exists('lotControlled', $data) || array_key_exists('lotCtl', $data)) {
                $item->lot_controlled = (bool) ($data['lotControlled'] ?? $data['lotCtl']);
            }
            if (array_key_exists('reorderPoint', $data) || array_key_exists('reorder', $data)) {
                $item->reorder_point = $data['reorderPoint'] ?? $data['reorder'] ?? null;
            }
            if (array_key_exists('safetyStock', $data) || array_key_exists('safety', $data)) {
                $item->safety_stock = $data['safetyStock'] ?? $data['safety'] ?? null;
            }
            if (array_key_exists('inventoryAccountCode', $data)) {
                $item->inventory_account_code = $data['inventoryAccountCode'];
            }
            if (array_key_exists('isActive', $data)) {
                $item->is_active = (bool) $data['isActive'];
            }

            RowVersion::bump($item);
            $item->save();

            $this->auditWriter->write(
                action: 'item.updated',
                entityType: 'item',
                entityId: $item->id,
                detail: ['sku' => $item->sku, 'name' => $item->name, 'isActive' => $item->is_active],
                actorId: $actorId,
            );

            return CreateItem::toArray($item);
        });
    }
}
