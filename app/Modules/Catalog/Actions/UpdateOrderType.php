<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Models\OrderType;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Concurrency\RowVersion;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * PATCH /api/v1/order-types/{id}
 */
final class UpdateOrderType
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
        if (! $this->permissionChecker->can($actorId, 'items.manage')) {
            $this->permissionChecker->assertCan($actorId, 'sales.orders.manage');
        }
        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($id, $data, $actorId, $companyId) {
            $orderType = OrderType::query()
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($orderType === null) {
                throw new NotFoundException('Order type not found.');
            }

            RowVersion::assertMatches($orderType, $data['rowVersion'] ?? null);

            if (array_key_exists('code', $data) && $data['code'] !== null) {
                $code = strtoupper(trim((string) $data['code']));
                if ($code === '') {
                    throw new BusinessRuleException('code cannot be empty.');
                }
                $dup = OrderType::query()
                    ->where('company_id', $companyId)
                    ->where('code', $code)
                    ->where('id', '!=', $orderType->id)
                    ->exists();
                if ($dup) {
                    throw new ConflictException("Order type '{$code}' already exists.");
                }
                $orderType->code = $code;
            }

            if (array_key_exists('name', $data) && $data['name'] !== null) {
                $name = trim((string) $data['name']);
                if ($name === '') {
                    throw new BusinessRuleException('name cannot be empty.');
                }
                $orderType->name = $name;
            }

            if (array_key_exists('workflow', $data)) {
                $orderType->workflow = $data['workflow'];
            }

            if (array_key_exists('isActive', $data)) {
                $orderType->is_active = (bool) $data['isActive'];
            }

            RowVersion::bump($orderType);
            $orderType->save();

            $this->auditWriter->write(
                action: 'order_type.updated',
                entityType: 'order_type',
                entityId: $orderType->id,
                detail: ['code' => $orderType->code, 'name' => $orderType->name, 'isActive' => $orderType->is_active],
                actorId: $actorId,
            );

            return CreateOrderType::toArray($orderType);
        });
    }
}
