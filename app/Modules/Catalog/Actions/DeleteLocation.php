<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Item;
use App\Models\Location;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DELETE /api/v1/locations/{id}
 *
 * Hard-delete only when the location has no stock history and is not a default
 * on any item; otherwise deactivate (is_active = false).
 */
final class DeleteLocation
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $id): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'items.manage');
        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($id, $actorId, $companyId) {
            $location = Location::query()
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($location === null) {
                throw NotFoundException::resource('Location', $id);
            }

            $referencedAsDefault = Item::query()
                ->where('company_id', $companyId)
                ->where('default_location_id', $id)
                ->exists();

            $hasStockHistory = false;
            if (Schema::hasTable('stock_movement')) {
                $hasStockHistory = DB::table('stock_movement')
                    ->where('company_id', $companyId)
                    ->where('location_id', $id)
                    ->exists();
            }

            if ($referencedAsDefault || $hasStockHistory) {
                if (! $location->is_active) {
                    throw new BusinessRuleException(
                        'Location is already inactive and cannot be deleted because it has history or is a default on an item.'
                    );
                }

                $location->is_active = false;
                $location->save();

                $this->auditWriter->write(
                    action: 'location.deactivated',
                    entityType: 'location',
                    entityId: $location->id,
                    detail: ['code' => $location->code, 'reason' => 'has_history_or_default'],
                    actorId: $actorId,
                );

                return [
                    'id' => (string) $location->id,
                    'deleted' => false,
                    'deactivated' => true,
                    'isActive' => false,
                ];
            }

            $code = $location->code;
            $location->delete();

            $this->auditWriter->write(
                action: 'location.deleted',
                entityType: 'location',
                entityId: $id,
                detail: ['code' => $code],
                actorId: $actorId,
            );

            return [
                'id' => $id,
                'deleted' => true,
                'deactivated' => false,
            ];
        });
    }
}
