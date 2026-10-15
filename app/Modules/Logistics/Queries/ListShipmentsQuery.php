<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Queries;

use App\Models\Consignment;
use App\Models\Shipment;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/** GET /api/v1/shipments — list (shipments & consignments view). */
final class ListShipmentsQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(?string $status = null, ?string $purchaseOrderId = null, int $limit = 50, int $offset = 0): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'logistics.view');

        $companyId = $this->companyContext->companyId();

        $query = Shipment::query()
            ->where('company_id', $companyId)
            ->with(['consignments', 'purchaseOrders'])
            ->orderByDesc('created_at');

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        if ($purchaseOrderId !== null && $purchaseOrderId !== '') {
            $query->whereHas('purchaseOrders', static function ($q) use ($purchaseOrderId) {
                $q->where('purchase_order_id', $purchaseOrderId);
            });
        }

        $total = (clone $query)->count();
        $rows = $query->limit($limit)->offset($offset)->get();

        return [
            'data' => $rows->map(fn (Shipment $s) => $this->toArray($s))->all(),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(Shipment $s): array
    {
        return [
            'id' => $s->id,
            'shipmentNo' => $s->shipment_no,
            'kind' => $s->kind,
            'transport' => $s->transport,
            'mode' => $s->mode,
            'booking' => $s->booking,
            'incoterm' => $s->incoterm,
            'status' => $s->status,
            'purchaseOrderIds' => $s->purchaseOrders->pluck('purchase_order_id')->all(),
            'consignmentCount' => $s->consignments->count(),
            'consignments' => $s->consignments->map(static fn (Consignment $c) => [
                'id' => $c->id,
                'consignmentNo' => $c->consignment_no,
                'itemId' => $c->item_id,
                'qty' => (string) $c->qty,
                'container' => $c->container,
                'bl' => $c->bl,
                'status' => $c->status,
                'eta' => $c->eta?->format('Y-m-d'),
            ])->all(),
            'createdAt' => $s->created_at?->toIso8601String(),
        ];
    }
}
