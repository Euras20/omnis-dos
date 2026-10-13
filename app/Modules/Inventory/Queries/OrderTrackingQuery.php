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
     * @return array{query: string, matches: list<array<string, mixed>>}
     */
    public function execute(?string $q): array
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
        if ($q === '') {
            throw new BusinessRuleException('Query parameter q is required.');
        }

        $companyId = $this->companyContext->companyId();
        $like = '%'.$q.'%';
        $matches = [];

        $salesOrders = DB::select(
            "SELECT id, order_no AS ref, status, order_date AS date, 'sales_order' AS kind
             FROM sales_order
             WHERE company_id = ? AND (order_no ILIKE ? OR id::text = ?)
             ORDER BY order_date DESC LIMIT 20",
            [$companyId, $like, $q],
        );
        foreach ($salesOrders as $r) {
            $matches[] = $this->hit($r);
        }

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
            'path' => ($paths[$r->kind] ?? '/').$r->id,
        ];
    }
}
