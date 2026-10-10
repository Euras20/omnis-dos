<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Exceptions\ConflictException;
use App\Models\ItemCategory;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/item-categories
 */
final class CreateItemCategory
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @param  array{code: string, name: string}  $data
     * @return array<string, mixed>
     */
    public function execute(array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'items.manage');
        $companyId = $this->companyContext->companyId();
        $code = strtoupper(trim($data['code']));

        return DB::transaction(function () use ($data, $companyId, $actorId, $code) {
            if (ItemCategory::query()->where('company_id', $companyId)->where('code', $code)->exists()) {
                throw new ConflictException("Item category '{$code}' already exists.");
            }

            $category = new ItemCategory;
            $category->id = Uuid::v7();
            $category->company_id = $companyId;
            $category->code = $code;
            $category->name = trim($data['name']);
            $category->is_active = true;
            $category->save();

            $this->auditWriter->write(
                action: 'item_category.created',
                entityType: 'item_category',
                entityId: $category->id,
                detail: ['code' => $category->code, 'name' => $category->name],
                actorId: $actorId,
            );

            return self::toArray($category);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(ItemCategory $category): array
    {
        return [
            'id' => (string) $category->id,
            'code' => $category->code,
            'name' => $category->name,
            'isActive' => (bool) $category->is_active,
            'rowVersion' => (int) $category->row_version,
        ];
    }
}
