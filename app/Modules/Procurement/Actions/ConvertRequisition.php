<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Models\Requisition;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Outbox\OutboxWriter;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * Converts an approved requisition into a draft purchase order via CreatePurchaseOrder.
 * Idempotent: if already converted, returns the existing PO id (409 if retry with different body is not applicable here).
 */
final class ConvertRequisition
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly CreatePurchaseOrder $createPurchaseOrder,
        private readonly AuditWriter $auditWriter,
        private readonly OutboxWriter $outboxWriter,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data  must include supplierId when preferred supplier absent
     * @return array<string, mixed>
     */
    public function execute(string $id, array $data = []): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'po.create');
        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($id, $companyId, $actorId, $data) {
            $req = Requisition::query()
                ->with('lines')
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            if ($req === null) {
                throw new NotFoundException('Requisition not found.');
            }
            if ($req->status === 'converted' && $req->converted_purchase_order_id) {
                throw new ConflictException(
                    'Requisition already converted to purchase order '.$req->converted_purchase_order_id
                );
            }
            if ($req->status !== 'approved') {
                throw new BusinessRuleException("Only approved requisitions can be converted (status: {$req->status}).");
            }

            $supplierId = (string) ($data['supplierId'] ?? $req->preferred_supplier_id ?? '');
            if ($supplierId === '') {
                throw new BusinessRuleException('supplierId is required to convert a requisition.');
            }

            $poPayload = [
                'supplierId' => $supplierId,
                'orderDate' => (string) ($data['orderDate'] ?? date('Y-m-d')),
                'memo' => $req->memo ? 'From requisition '.$req->req_no.': '.$req->memo : 'From requisition '.$req->req_no,
                'lines' => $req->lines->map(static fn ($l) => [
                    'itemId' => $l->item_id,
                    'description' => $l->description,
                    'qty' => (string) $l->qty,
                    'unit' => $l->unit,
                    'unitPriceMinor' => $l->estimated_unit_price_minor,
                ])->all(),
            ];

            $po = $this->createPurchaseOrder->execute($poPayload);

            $req->status = 'converted';
            $req->converted_purchase_order_id = $po['id'];
            $req->save();

            $this->auditWriter->write(
                action: 'requisition.converted',
                entityType: 'requisition',
                entityId: $req->id,
                detail: ['reqNo' => $req->req_no, 'purchaseOrderId' => $po['id']],
                actorId: $actorId,
            );
            $this->outboxWriter->record([
                'aggregate_type' => 'requisition',
                'aggregate_id' => $req->id,
                'event_type' => 'requisition.converted',
                'payload' => [
                    'requisitionId' => $req->id,
                    'purchaseOrderId' => $po['id'],
                ],
            ]);

            return [
                'id' => $req->id,
                'reqNo' => $req->req_no,
                'status' => $req->status,
                'purchaseOrderId' => $po['id'],
                'poNo' => $po['poNo'] ?? null,
            ];
        });
    }
}
