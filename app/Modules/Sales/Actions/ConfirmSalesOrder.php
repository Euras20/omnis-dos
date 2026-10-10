<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\SalesOrder;
use App\Modules\Sales\Domain\SalesOrderStatus;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Outbox\OutboxWriter;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/sales-orders/{id}/confirm — confirm a draft sales order.
 */
final class ConfirmSalesOrder
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
        private readonly OutboxWriter $outboxWriter,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $id): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'sales.manage');

        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($id, $companyId, $actorId) {
            $order = SalesOrder::query()
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($order === null) {
                throw new NotFoundException('Sales order not found.');
            }

            if ($order->status !== SalesOrderStatus::DRAFT) {
                throw new BusinessRuleException(
                    "Only draft sales orders can be confirmed (current status: {$order->status})."
                );
            }

            $order->status = SalesOrderStatus::CONFIRMED;
            $order->confirmed_by = $actorId;
            $order->confirmed_at = now();
            $order->save();

            $this->auditWriter->write(
                action: 'sales_order.confirmed',
                entityType: 'sales_order',
                entityId: $order->id,
                detail: [
                    'orderNo' => $order->order_no,
                    'customerId' => $order->customer_id,
                ],
                actorId: $actorId,
            );

            $this->outboxWriter->record([
                'aggregate_type' => 'sales_order',
                'aggregate_id' => $order->id,
                'event_type' => 'sales_order.confirmed',
                'payload' => [
                    'salesOrderId' => $order->id,
                    'orderNo' => $order->order_no,
                ],
            ]);

            return [
                'id' => $order->id,
                'orderNo' => $order->order_no,
                'status' => $order->status,
                'confirmedAt' => $order->confirmed_at?->toIso8601String(),
            ];
        });
    }
}
