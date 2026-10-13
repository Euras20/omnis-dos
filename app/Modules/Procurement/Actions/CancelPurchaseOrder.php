<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Modules\Procurement\Domain\PurchaseOrderStatus;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Outbox\OutboxWriter;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/purchase-orders/{id}/cancel
 *
 * Allowed when status is draft, or approved with no received quantity on any line.
 * Never posts or reverses stock/GL — received POs must stay open or be closed.
 */
final class CancelPurchaseOrder
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
        $this->permissionChecker->assertCan($actorId, 'po.cancel');

        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($id, $companyId, $actorId) {
            $po = PurchaseOrder::query()
                ->with('lines')
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($po === null) {
                throw new NotFoundException('Purchase order not found.');
            }

            if ($po->status === PurchaseOrderStatus::CANCELLED) {
                throw new BusinessRuleException('Purchase order is already cancelled.');
            }

            if ($po->status === PurchaseOrderStatus::CLOSED) {
                throw new BusinessRuleException('Closed purchase orders cannot be cancelled.');
            }

            if (! in_array($po->status, [PurchaseOrderStatus::DRAFT, PurchaseOrderStatus::APPROVED], true)) {
                throw new BusinessRuleException(
                    "Purchase order cannot be cancelled from status '{$po->status}'."
                );
            }

            $hasReceived = $po->lines->contains(
                static fn (PurchaseOrderLine $line) => (float) $line->qty_received > 0
            );

            if ($hasReceived) {
                throw new BusinessRuleException(
                    'Cannot cancel a purchase order that has received quantities. Close residual open quantity instead.'
                );
            }

            $previous = $po->status;
            $po->status = PurchaseOrderStatus::CANCELLED;
            $po->save();

            $this->auditWriter->write(
                action: 'purchase_order.cancelled',
                entityType: 'purchase_order',
                entityId: $po->id,
                detail: [
                    'poNo' => $po->po_no,
                    'supplierId' => $po->supplier_id,
                    'previousStatus' => $previous,
                ],
                actorId: $actorId,
            );

            $this->outboxWriter->record([
                'aggregate_type' => 'purchase_order',
                'aggregate_id' => $po->id,
                'event_type' => 'purchase_order.cancelled',
                'payload' => [
                    'purchaseOrderId' => $po->id,
                    'poNo' => $po->po_no,
                    'previousStatus' => $previous,
                ],
            ]);

            return [
                'id' => $po->id,
                'poNo' => $po->po_no,
                'status' => $po->status,
                'previousStatus' => $previous,
            ];
        });
    }
}
