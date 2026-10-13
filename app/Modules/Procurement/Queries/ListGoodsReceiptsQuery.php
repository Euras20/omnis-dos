<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Queries;

use App\Models\GoodsReceipt;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * GET /api/v1/goods-receipts — register with optional filters.
 */
final class ListGoodsReceiptsQuery
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
        $this->permissionChecker->assertCan($actorId, 'stock.move');

        $companyId = $this->companyContext->companyId();
        $limit = min(max((int) ($filters['limit'] ?? 50), 1), 200);
        $offset = max((int) ($filters['offset'] ?? 0), 0);

        $q = GoodsReceipt::query()
            ->with(['lines', 'purchaseOrder:id,po_no,supplier_id'])
            ->where('company_id', $companyId)
            ->orderByDesc('receipt_date')
            ->orderByDesc('created_at');

        if (! empty($filters['purchaseOrderId'])) {
            $q->where('purchase_order_id', (string) $filters['purchaseOrderId']);
        }
        if (! empty($filters['status'])) {
            $q->where('status', (string) $filters['status']);
        }
        if (! empty($filters['from'])) {
            $q->where('receipt_date', '>=', (string) $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->where('receipt_date', '<=', (string) $filters['to']);
        }
        if (! empty($filters['locationId'])) {
            $q->where('location_id', (string) $filters['locationId']);
        }
        if (! empty($filters['q'])) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']).'%';
            $q->where(function ($inner) use ($term) {
                $inner->where('grn_no', 'ilike', $term)
                    ->orWhere('memo', 'ilike', $term);
            });
        }

        $total = (clone $q)->count();
        $rows = $q->limit($limit)->offset($offset)->get();

        return [
            'data' => $rows->map(static function (GoodsReceipt $grn) {
                return [
                    'id' => $grn->id,
                    'grnNo' => $grn->grn_no,
                    'purchaseOrderId' => $grn->purchase_order_id,
                    'poNo' => $grn->purchaseOrder?->po_no,
                    'locationId' => $grn->location_id,
                    'receiptDate' => $grn->receipt_date?->format('Y-m-d'),
                    'status' => $grn->status,
                    'journalId' => $grn->journal_id,
                    'memo' => $grn->memo,
                    'lineCount' => $grn->lines->count(),
                    'createdAt' => $grn->created_at?->toIso8601String(),
                ];
            })->all(),
            'total' => $total,
        ];
    }
}
