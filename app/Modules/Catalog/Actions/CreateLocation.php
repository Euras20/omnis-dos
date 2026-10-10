<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Models\Location;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/locations
 */
final class CreateLocation
{
    private const TYPES = ['receiving', 'quarantine', 'rejected', 'store', 'dispatch'];

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

        $locType = (string) ($data['locType'] ?? $data['type'] ?? '');
        if (! in_array($locType, self::TYPES, true)) {
            throw new BusinessRuleException(
                'locType must be one of: '.implode(', ', self::TYPES)
            );
        }

        $companyId = $this->companyContext->companyId();
        $code = strtoupper(trim((string) $data['code']));

        return DB::transaction(function () use ($data, $locType, $companyId, $actorId, $code) {
            if (Location::query()->where('company_id', $companyId)->where('code', $code)->exists()) {
                throw new ConflictException("A location with code '{$code}' already exists.");
            }

            $location = new Location;
            $location->id = Uuid::v7();
            $location->company_id = $companyId;
            $location->code = $code;
            $location->name = trim((string) $data['name']);
            $location->warehouse = $data['warehouse'] ?? null;
            $location->loc_type = $locType;
            $location->keeper_id = $data['keeperId'] ?? null;
            $location->is_assignable = (bool) ($data['isAssignable'] ?? $data['assignable'] ?? false);
            $location->capacity = isset($data['capacity']) ? (int) $data['capacity'] : null;
            $location->is_active = true;
            $location->save();

            $this->auditWriter->write(
                action: 'location.created',
                entityType: 'location',
                entityId: $location->id,
                detail: ['code' => $location->code, 'name' => $location->name, 'locType' => $location->loc_type],
                actorId: $actorId,
            );

            return self::toArray($location);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(Location $location): array
    {
        return [
            'id' => (string) $location->id,
            'code' => $location->code,
            'name' => $location->name,
            'warehouse' => $location->warehouse,
            'locType' => $location->loc_type,
            'keeperId' => $location->keeper_id,
            'isAssignable' => (bool) $location->is_assignable,
            'capacity' => $location->capacity,
            'isActive' => (bool) $location->is_active,
            'rowVersion' => (int) $location->row_version,
            'createdAt' => $location->created_at?->toIso8601String(),
            'updatedAt' => $location->updated_at?->toIso8601String(),
        ];
    }
}
