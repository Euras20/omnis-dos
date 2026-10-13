<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\PurchaseOrder;
use App\Modules\Procurement\Domain\PurchaseOrderStatus;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Outbox\OutboxWriter;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/purchase-orders/{id}/close
 *
 * Marks an approved PO as closed. No stock movements and no journals.
 * Allowed only from approved status (partial residual open qty is permitted —
 * close is an operational decision that residual will not be received).
 */
final class ClosePurchaseOrder
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
        $this->permissionChecker->assertCan($actorId, 'po.close');

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

            if ($po->status === PurchaseOrderStatus::CLOSED) {
                throw new BusinessRuleException('Purchase order is already closed.');
            }

            if ($po->status !== PurchaseOrderStatus::APPROVED) {
                throw new BusinessRuleException(
                    "Only approved purchase orders can be closed (current status: {$po->status})."
                );
            }

            $qtyOrdered = 0.0;
            $qtyReceived = 0.0;
            foreach ($po->lines as $line) {
                $qtyOrdered += (float) $line->qty;
                $qtyReceived += (float) $line->qty_received;
            }

            $previous = $po->status;
            $po->status = PurchaseOrderStatus::CLOSED;
            $po->save();

            $this->auditWriter->write(
                action: 'purchase_order.closed',
                entityType: 'purchase_order',
                entityId: $po->id,
                detail: [
                    'poNo' => $po->po_no,
                    'supplierId' => $po->supplier_id,
                    'previousStatus' => $previous,
                    'qtyOrdered' => $qtyOrdered,
                    'qtyReceived' => $qtyReceived,
                ],
                actorId: $actorId,
            );

            $this->outboxWriter->record([
                'aggregate_type' => 'purchase_order',
                'aggregate_id' => $po->id,
                'event_type' => 'purchase_order.closed',
                'payload' => [
                    'purchaseOrderId' => $po->id,
                    'poNo' => $po->po_no,
                    'qtyOrdered' => $qtyOrdered,
                    'qtyReceived' => $qtyReceived,
                ],
            ]);

            return [
                'id' => $po->id,
                'poNo' => $po->po_no,
                'status' => $po->status,
                'previousStatus' => $previous,
                'qtyOrdered' => (string) $qtyOrdered,
                'qtyReceived' => (string) $qtyReceived,
            ];
        });
    }
}
