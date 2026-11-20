<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Queries;

use App\Exceptions\BusinessRuleException;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/tracking?q= — search SO / PO / quotation / GRN / invoice / lot / delivery refs.
 * Supports the frontend Order tracking screen.
 */
final class OrderTrackingQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{query: string, matches: list<array<string, mixed>>}
     */
    public function execute(?string $q, array $filters = []): array
    {
        $actorId = $this->companyContext->userId();
        // Order tracking is used by sales + stock; either view permission is enough.
        if (
            ! $this->permissionChecker->can($actorId, 'sales.orders.view')
            && ! $this->permissionChecker->can($actorId, 'stock.view')
            && ! $this->permissionChecker->can($actorId, 'po.view')
        ) {
            $this->permissionChecker->assertCan($actorId, 'sales.orders.view');
        }

        $q = trim((string) $q);
        $customerId = isset($filters['customerId']) ? (string) $filters['customerId'] : null;
        $orderTypeId = isset($filters['orderTypeId']) ? (string) $filters['orderTypeId'] : null;
        $channel = isset($filters['channel']) ? strtolower((string) $filters['channel']) : null;

        if ($q === '' && $customerId === null && $orderTypeId === null && $channel === null) {
            throw new BusinessRuleException('Provide q, customerId, orderTypeId, or channel.');
        }

        $companyId = $this->companyContext->companyId();
        $like = '%'.$q.'%';
        $matches = [];

        $soSql = "SELECT id, order_no AS ref, status, order_date AS date, 'sales_order' AS kind,
                         customer_id, order_type_id, sales_channel
                  FROM sales_order
                  WHERE company_id = ?";
        $soBind = [$companyId];
        if ($q !== '') {
            $soSql .= ' AND (order_no ILIKE ? OR id::text = ?)';
            $soBind[] = $like;
            $soBind[] = $q;
        }
        if ($customerId) {
            $soSql .= ' AND customer_id = ?';
            $soBind[] = $customerId;
        }
        if ($orderTypeId) {
            $soSql .= ' AND order_type_id = ?';
            $soBind[] = $orderTypeId;
        }
        if ($channel) {
            $soSql .= ' AND sales_channel = ?';
            $soBind[] = $channel;
        }
        $soSql .= ' ORDER BY order_date DESC LIMIT 50';

        foreach (DB::select($soSql, $soBind) as $r) {
            $matches[] = $this->hit($r);
        }

        if ($customerId === null && $orderTypeId === null && $channel === null && $q !== '') {
            $pos = DB::select(
                "SELECT id, po_no AS ref, status, order_date AS date, 'purchase_order' AS kind
                 FROM purchase_order
                 WHERE company_id = ? AND (po_no ILIKE ? OR id::text = ?)
                 ORDER BY order_date DESC LIMIT 20",
                [$companyId, $like, $q],
            );
            foreach ($pos as $r) {
                $matches[] = $this->hit($r);
            }

        $quotes = DB::select(
            "SELECT id, quotation_no AS ref, status, quotation_date AS date, 'quotation' AS kind
             FROM quotation
             WHERE company_id = ? AND (quotation_no ILIKE ? OR id::text = ?)
             ORDER BY quotation_date DESC LIMIT 20",
            [$companyId, $like, $q],
        );
        foreach ($quotes as $r) {
            $matches[] = $this->hit($r);
        }

        $invoices = DB::select(
            "SELECT id, invoice_no AS ref, status, invoice_date AS date, 'invoice' AS kind
             FROM invoice
             WHERE company_id = ? AND (invoice_no ILIKE ? OR id::text = ?)
             ORDER BY invoice_date DESC LIMIT 20",
            [$companyId, $like, $q],
        );
        foreach ($invoices as $r) {
            $matches[] = $this->hit($r);
        }

        $grns = DB::select(
            "SELECT id, grn_no AS ref, status, receipt_date AS date, 'goods_receipt' AS kind
             FROM goods_receipt
             WHERE company_id = ? AND (grn_no ILIKE ? OR id::text = ?)
             ORDER BY receipt_date DESC LIMIT 20",
            [$companyId, $like, $q],
        );
        foreach ($grns as $r) {
            $matches[] = $this->hit($r);
        }

        $lots = DB::select(
            "SELECT DISTINCT lot AS ref, MIN(movement_date) AS date
             FROM stock_movement
             WHERE company_id = ? AND lot ILIKE ?
             GROUP BY lot
             ORDER BY date DESC LIMIT 20",
            [$companyId, $like],
        );
            foreach ($lots as $r) {
                $matches[] = [
                    'kind' => 'lot',
                    'id' => null,
                    'ref' => $r->ref,
                    'status' => null,
                    'date' => $r->date,
                    'path' => '/stock/traceability/'.rawurlencode((string) $r->ref),
                ];
            }
        }

        return ['query' => $q, 'matches' => $matches];
    }

    /**
     * @return array<string, mixed>
     */
    private function hit(object $r): array
    {
        $paths = [
            'sales_order' => '/sales-orders/',
            'purchase_order' => '/purchase-orders/',
            'quotation' => '/quotations/',
            'invoice' => '/invoices/',
            'goods_receipt' => '/goods-receipts/',
        ];

        return [
            'kind' => $r->kind,
            'id' => $r->id,
            'ref' => $r->ref,
            'status' => $r->status ?? null,
            'date' => $r->date,
            'customerId' => $r->customer_id ?? null,
            'orderTypeId' => $r->order_type_id ?? null,
            'salesChannel' => $r->sales_channel ?? null,
            'path' => ($paths[$r->kind] ?? '/').$r->id,
        ];
    }
}
