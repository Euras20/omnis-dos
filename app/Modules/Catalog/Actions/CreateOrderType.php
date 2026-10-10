<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Exceptions\ConflictException;
use App\Models\OrderType;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/order-types
 */
final class CreateOrderType
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @param  array{code: string, name: string, workflow?: ?string}  $data
     * @return array<string, mixed>
     */
    public function execute(array $data): array
    {
        $actorId = $this->companyContext->userId();
        // API lists master.manage; catalogue uses items.manage for catalog masters.
        if (! $this->permissionChecker->can($actorId, 'items.manage')) {
            $this->permissionChecker->assertCan($actorId, 'sales.orders.manage');
        }

        $companyId = $this->companyContext->companyId();
        $code = strtoupper(trim($data['code']));

        return DB::transaction(function () use ($data, $companyId, $actorId, $code) {
            if (OrderType::query()->where('company_id', $companyId)->where('code', $code)->exists()) {
                throw new ConflictException("Order type '{$code}' already exists.");
            }

            $orderType = new OrderType;
            $orderType->id = Uuid::v7();
            $orderType->company_id = $companyId;
            $orderType->code = $code;
            $orderType->name = trim($data['name']);
            $orderType->workflow = $data['workflow'] ?? null;
            $orderType->is_active = true;
            $orderType->save();

            $this->auditWriter->write(
                action: 'order_type.created',
                entityType: 'order_type',
                entityId: $orderType->id,
                detail: ['code' => $orderType->code, 'name' => $orderType->name],
                actorId: $actorId,
            );

            return self::toArray($orderType);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(OrderType $orderType): array
    {
        return [
            'id' => (string) $orderType->id,
            'code' => $orderType->code,
            'name' => $orderType->name,
            'workflow' => $orderType->workflow,
            'isActive' => (bool) $orderType->is_active,
            'rowVersion' => (int) $orderType->row_version,
        ];
    }
}
