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
 * POST /api/v1/purchase-orders/{id}/approve — SoD: approver ≠ creator.
 */
final class ApprovePurchaseOrder
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
        $this->permissionChecker->assertCan($actorId, 'po.approve');

        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($id, $companyId, $actorId) {
            $po = PurchaseOrder::query()
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($po === null) {
                throw new NotFoundException('Purchase order not found.');
            }

            if ($po->status !== PurchaseOrderStatus::DRAFT) {
                throw new BusinessRuleException(
                    "Only draft purchase orders can be approved (current status: {$po->status})."
                );
            }

            if ($po->created_by !== null && $po->created_by === $actorId) {
                throw new BusinessRuleException(
                    'Segregation of duties: the approver must differ from the creator.'
                );
            }

            $po->status = PurchaseOrderStatus::APPROVED;
            $po->approved_by = $actorId;
            $po->approved_at = now();
            $po->save();

            $this->auditWriter->write(
                action: 'purchase_order.approved',
                entityType: 'purchase_order',
                entityId: $po->id,
                detail: [
                    'poNo' => $po->po_no,
                    'supplierId' => $po->supplier_id,
                    'createdBy' => $po->created_by,
                ],
                actorId: $actorId,
            );

            $this->outboxWriter->record([
                'aggregate_type' => 'purchase_order',
                'aggregate_id' => $po->id,
                'event_type' => 'purchase_order.approved',
                'payload' => [
                    'purchaseOrderId' => $po->id,
                    'poNo' => $po->po_no,
                ],
            ]);

            return [
                'id' => $po->id,
                'poNo' => $po->po_no,
                'status' => $po->status,
                'approvedAt' => $po->approved_at?->toIso8601String(),
            ];
        });
    }
}
