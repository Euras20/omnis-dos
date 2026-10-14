<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Models\ItemCategory;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Concurrency\RowVersion;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * PATCH /api/v1/item-categories/{id}
 */
final class UpdateItemCategory
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
            $category = ItemCategory::query()
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($category === null) {
                throw new NotFoundException('Item category not found.');
            }

            RowVersion::assertMatches($category, $data['rowVersion'] ?? null);

            if (array_key_exists('code', $data) && $data['code'] !== null) {
                $code = strtoupper(trim((string) $data['code']));
                if ($code === '') {
                    throw new BusinessRuleException('code cannot be empty.');
                }
                $dup = ItemCategory::query()
                    ->where('company_id', $companyId)
                    ->where('code', $code)
                    ->where('id', '!=', $category->id)
                    ->exists();
                if ($dup) {
                    throw new ConflictException("Item category '{$code}' already exists.");
                }
                $category->code = $code;
            }

            if (array_key_exists('name', $data) && $data['name'] !== null) {
                $name = trim((string) $data['name']);
                if ($name === '') {
                    throw new BusinessRuleException('name cannot be empty.');
                }
                $category->name = $name;
            }

            if (array_key_exists('isActive', $data)) {
                $category->is_active = (bool) $data['isActive'];
            }

            RowVersion::bump($category);
            $category->save();

            $this->auditWriter->write(
                action: 'item_category.updated',
                entityType: 'item_category',
                entityId: $category->id,
                detail: ['code' => $category->code, 'name' => $category->name, 'isActive' => $category->is_active],
                actorId: $actorId,
            );

            return CreateItemCategory::toArray($category);
        });
    }
}
