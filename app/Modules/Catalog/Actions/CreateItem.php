<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Location;
use App\Models\Party;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/items
 */
final class CreateItem
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
    public function execute(array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'items.manage');
        $companyId = $this->companyContext->companyId();
        $sku = strtoupper(trim((string) $data['sku']));

        return DB::transaction(function () use ($data, $companyId, $actorId, $sku) {
            if (Item::query()->where('company_id', $companyId)->where('sku', $sku)->exists()) {
                throw new ConflictException("An item with SKU '{$sku}' already exists.");
            }

            $categoryCode = isset($data['categoryCode']) ? strtoupper(trim((string) $data['categoryCode'])) : null;
            if ($categoryCode !== null) {
                $categoryExists = ItemCategory::query()
                    ->where('company_id', $companyId)
                    ->where('code', $categoryCode)
                    ->where('is_active', true)
                    ->exists();
                if (! $categoryExists) {
                    throw new BusinessRuleException("Item category '{$categoryCode}' was not found or is inactive.");
                }
            }

            $defaultLocationId = $data['defaultLocationId'] ?? null;
            if ($defaultLocationId !== null) {
                $loc = Location::query()
                    ->where('company_id', $companyId)
                    ->where('id', $defaultLocationId)
                    ->where('is_active', true)
                    ->first();
                if ($loc === null) {
                    throw new BusinessRuleException('defaultLocationId was not found or is inactive.');
                }
            }

            $defaultSupplierId = $data['defaultSupplierId'] ?? null;
            if ($defaultSupplierId !== null) {
                $supplier = Party::query()
                    ->where('company_id', $companyId)
                    ->where('id', $defaultSupplierId)
                    ->where('party_type', 'supplier')
                    ->where('is_active', true)
                    ->first();
                if ($supplier === null) {
                    throw new BusinessRuleException('defaultSupplierId must be an active supplier.');
                }
            }

            $item = new Item;
            $item->id = Uuid::v7();
            $item->company_id = $companyId;
            $item->sku = $sku;
            $item->name = trim((string) $data['name']);
            $item->item_type = trim((string) ($data['itemType'] ?? $data['type'] ?? 'Trading good'));
            $item->category_code = $categoryCode;
            $item->unit = (string) ($data['unit'] ?? 'pcs');
            $item->list_price_minor = (int) ($data['listPriceMinor'] ?? $data['priceMinor'] ?? 0);
            $item->standard_cost_minor = (int) ($data['standardCostMinor'] ?? $data['costMinor'] ?? 0);
            $item->buy_price_minor = isset($data['buyPriceMinor']) ? (int) $data['buyPriceMinor'] : null;
            $item->weight_kg = $data['weightKg'] ?? $data['weight'] ?? null;
            $item->dimensions = $data['dimensions'] ?? null;
            $item->default_location_id = $defaultLocationId;
            $item->default_supplier_id = $defaultSupplierId;
            $item->inventory_policy = (string) ($data['inventoryPolicy'] ?? $data['policy'] ?? 'Buy');
            $item->lot_controlled = (bool) ($data['lotControlled'] ?? $data['lotCtl'] ?? true);
            $item->reorder_point = $data['reorderPoint'] ?? $data['reorder'] ?? null;
            $item->safety_stock = $data['safetyStock'] ?? $data['safety'] ?? null;
            $item->inventory_account_code = $data['inventoryAccountCode'] ?? null;
            $item->is_active = true;
            $item->save();

            $this->auditWriter->write(
                action: 'item.created',
                entityType: 'item',
                entityId: $item->id,
                detail: ['sku' => $item->sku, 'name' => $item->name, 'itemType' => $item->item_type],
                actorId: $actorId,
            );

            return self::toArray($item);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(Item $item): array
    {
        return [
            'id' => (string) $item->id,
            'sku' => $item->sku,
            'name' => $item->name,
            'itemType' => $item->item_type,
            'categoryCode' => $item->category_code,
            'unit' => $item->unit,
            'listPriceMinor' => (int) $item->list_price_minor,
            'standardCostMinor' => (int) $item->standard_cost_minor,
            'buyPriceMinor' => $item->buy_price_minor !== null ? (int) $item->buy_price_minor : null,
            'weightKg' => $item->weight_kg !== null ? (float) $item->weight_kg : null,
            'dimensions' => $item->dimensions,
            'defaultLocationId' => $item->default_location_id,
            'defaultSupplierId' => $item->default_supplier_id,
            'inventoryPolicy' => $item->inventory_policy,
            'lotControlled' => (bool) $item->lot_controlled,
            'reorderPoint' => $item->reorder_point !== null ? (float) $item->reorder_point : null,
            'safetyStock' => $item->safety_stock !== null ? (float) $item->safety_stock : null,
            'inventoryAccountCode' => $item->inventory_account_code,
            'isActive' => (bool) $item->is_active,
            'rowVersion' => (int) $item->row_version,
            'createdAt' => $item->created_at?->toIso8601String(),
            'updatedAt' => $item->updated_at?->toIso8601String(),
        ];
    }
}
