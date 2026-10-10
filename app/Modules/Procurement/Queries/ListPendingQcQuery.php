<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Queries;

use App\Models\GoodsReceiptLine;
use App\Modules\Procurement\Domain\QcStatus;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * GET /api/v1/goods-receipts/pending-qc
 */
final class ListPendingQcQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, total: int}
     */
    public function execute(array $filters = []): array
    {
        $actorId = $this->companyContext->userId();
        // API lists stock.move + po.view; enforce stock.move as primary.
        $this->permissionChecker->assertCan($actorId, 'stock.move');

        $companyId = $this->companyContext->companyId();
        $limit = min(max((int) ($filters['limit'] ?? 50), 1), 200);
        $offset = max((int) ($filters['offset'] ?? 0), 0);

        $q = GoodsReceiptLine::query()
            ->where('company_id', $companyId)
            ->whereIn('qc_status', [QcStatus::PENDING, QcStatus::PARTIAL])
            ->orderBy('created_at');

        if (! empty($filters['goodsReceiptId'])) {
            $q->where('goods_receipt_id', (string) $filters['goodsReceiptId']);
        }
        if (! empty($filters['itemId'])) {
            $q->where('item_id', (string) $filters['itemId']);
        }

        $total = (clone $q)->count();
        $rows = $q->limit($limit)->offset($offset)->get();

        return [
            'data' => $rows->map(static fn (GoodsReceiptLine $l) => [
                'id' => $l->id,
                'goodsReceiptId' => $l->goods_receipt_id,
                'purchaseOrderLineId' => $l->purchase_order_line_id,
                'itemId' => $l->item_id,
                'lineNo' => $l->line_no,
                'qtyReceived' => (string) $l->qty_received,
                'qtyCertified' => (string) $l->qty_certified,
                'qtyQuarantine' => (string) $l->qty_quarantine,
                'qtyRejected' => (string) $l->qty_rejected,
                'lot' => $l->lot,
                'unitCostMinor' => $l->unit_cost_minor,
                'qcStatus' => $l->qc_status,
            ])->all(),
            'total' => $total,
        ];
    }
}
