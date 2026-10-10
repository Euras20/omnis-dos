<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Item;
use App\Models\NumberSequence;
use App\Models\Party;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SalesOrderLine;
use App\Modules\Procurement\Domain\PurchaseOrderStatus;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Outbox\OutboxWriter;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/purchase-orders — create a PO; lines may serve customer order lines or stocking.
 */
final class CreatePurchaseOrder
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
        private readonly OutboxWriter $outboxWriter,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function execute(array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'po.create');

        $companyId = $this->companyContext->companyId();
        $supplierId = (string) ($data['supplierId'] ?? '');
        $orderDate = (string) ($data['orderDate'] ?? $data['date'] ?? '');
        $rawLines = $data['lines'] ?? [];

        if ($supplierId === '') {
            throw new BusinessRuleException('supplierId is required.');
        }
        if ($orderDate === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $orderDate)) {
            throw new BusinessRuleException('orderDate must be YYYY-MM-DD.');
        }
        if (! is_array($rawLines) || $rawLines === []) {
            throw new BusinessRuleException('At least one purchase order line is required.');
        }

        $supplier = Party::query()
            ->where('company_id', $companyId)
            ->where('id', $supplierId)
            ->where('party_type', 'supplier')
            ->where('is_active', true)
            ->first();
        if ($supplier === null) {
            throw new NotFoundException('Supplier not found.');
        }

        $currency = strtoupper((string) ($data['currency'] ?? $supplier->currency ?? 'GHS'));
        $expectedDate = isset($data['expectedDate']) ? (string) $data['expectedDate'] : null;
        $memo = isset($data['memo']) ? trim((string) $data['memo']) : null;
        $requestId = $this->companyContext->requestId();

        return DB::transaction(function () use (
            $companyId,
            $supplierId,
            $orderDate,
            $expectedDate,
            $rawLines,
            $currency,
            $memo,
            $actorId,
            $requestId,
        ) {
            $poId = Uuid::v7();
            $poNo = $this->nextNumber($companyId, $orderDate, 'purchase_order');

            $headerNet = 0;
            $headerTax = 0;
            $lineRows = [];

            foreach ($rawLines as $index => $raw) {
                if (! is_array($raw)) {
                    throw new BusinessRuleException("Line {$index} is invalid.");
                }
                $itemId = isset($raw['itemId']) ? (string) $raw['itemId'] : null;
                $description = trim((string) ($raw['description'] ?? ''));
                $qty = (string) ($raw['qty'] ?? '');
                $unitPrice = (int) ($raw['unitPriceMinor'] ?? $raw['unit_price_minor'] ?? 0);
                $unit = (string) ($raw['unit'] ?? 'pcs');
                $taxMinor = (int) ($raw['taxMinor'] ?? 0);
                $salesOrderLineId = isset($raw['salesOrderLineId']) ? (string) $raw['salesOrderLineId'] : null;
                $lineCategory = (string) ($raw['lineCategory'] ?? ($salesOrderLineId ? 'direct' : 'stocking'));
                if (! in_array($lineCategory, ['stocking', 'direct', 'import'], true)) {
                    $lineCategory = 'stocking';
                }

                if ($qty === '' || ! is_numeric($qty) || (float) $qty <= 0) {
                    throw new BusinessRuleException("Line {$index}: qty must be positive.");
                }
                if ($unitPrice < 0) {
                    throw new BusinessRuleException("Line {$index}: unitPriceMinor cannot be negative.");
                }

                if ($itemId !== null && $itemId !== '') {
                    $item = Item::query()
                        ->where('company_id', $companyId)
                        ->where('id', $itemId)
                        ->where('is_active', true)
                        ->first();
                    if ($item === null) {
                        throw new NotFoundException("Item not found for line {$index}.");
                    }
                    if ($description === '') {
                        $description = (string) $item->name;
                    }
                } elseif ($description === '') {
                    throw new BusinessRuleException("Line {$index}: description is required when itemId is omitted.");
                }

                if ($salesOrderLineId !== null && $salesOrderLineId !== '') {
                    $sol = SalesOrderLine::query()
                        ->where('company_id', $companyId)
                        ->where('id', $salesOrderLineId)
                        ->first();
                    if ($sol === null) {
                        throw new NotFoundException("Sales order line not found for line {$index}.");
                    }
                } else {
                    $salesOrderLineId = null;
                }

                $net = (int) round((float) $qty * $unitPrice);
                $gross = $net + $taxMinor;
                $headerNet += $net;
                $headerTax += $taxMinor;

                $lineRows[] = [
                    'id' => Uuid::v7(),
                    'company_id' => $companyId,
                    'purchase_order_id' => $poId,
                    'line_no' => $index + 1,
                    'item_id' => $itemId ?: null,
                    'sales_order_line_id' => $salesOrderLineId,
                    'description' => $description,
                    'qty' => $qty,
                    'unit' => $unit,
                    'unit_price_minor' => $unitPrice,
                    'net_minor' => $net,
                    'tax_minor' => $taxMinor,
                    'gross_minor' => $gross,
                    'qty_received' => 0,
                    'line_category' => $lineCategory,
                ];
            }

            $po = new PurchaseOrder;
            $po->fill([
                'id' => $poId,
                'company_id' => $companyId,
                'po_no' => $poNo,
                'supplier_id' => $supplierId,
                'order_date' => $orderDate,
                'expected_date' => $expectedDate,
                'currency' => $currency,
                'status' => PurchaseOrderStatus::DRAFT,
                'net_minor' => $headerNet,
                'tax_total_minor' => $headerTax,
                'gross_minor' => $headerNet + $headerTax,
                'memo' => $memo,
                'created_by' => $actorId,
                'request_id' => $requestId,
            ]);
            $po->save();

            foreach ($lineRows as $row) {
                $line = new PurchaseOrderLine;
                $line->fill($row);
                $line->save();
            }

            $this->auditWriter->write(
                action: 'purchase_order.created',
                entityType: 'purchase_order',
                entityId: $poId,
                detail: [
                    'poNo' => $poNo,
                    'supplierId' => $supplierId,
                    'grossMinor' => $headerNet + $headerTax,
                    'lineCount' => count($lineRows),
                ],
                actorId: $actorId,
            );

            $this->outboxWriter->record([
                'aggregate_type' => 'purchase_order',
                'aggregate_id' => $poId,
                'event_type' => 'purchase_order.created',
                'payload' => [
                    'purchaseOrderId' => $poId,
                    'poNo' => $poNo,
                    'supplierId' => $supplierId,
                ],
            ]);

            return $this->toArray(PurchaseOrder::query()->with('lines')->findOrFail($poId));
        });
    }

    private function nextNumber(string $companyId, string $date, string $docType): string
    {
        $year = substr($date, 0, 4);
        $seq = NumberSequence::query()
            ->where('company_id', $companyId)
            ->where('doc_type', $docType)
            ->where('fiscal_year', $year)
            ->lockForUpdate()
            ->first();

        if ($seq === null) {
            $seq = new NumberSequence;
            $seq->id = Uuid::v7();
            $seq->company_id = $companyId;
            $seq->doc_type = $docType;
            $seq->fiscal_year = $year;
            $seq->next_value = 1;
            $seq->prefix = 'PO';
            $seq->save();
        }

        $value = $seq->next_value;
        $seq->next_value = $value + 1;
        $seq->save();

        $prefix = $seq->prefix ?: 'PO';

        return sprintf('%s-%s-%05d', $prefix, $year, $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(PurchaseOrder $po): array
    {
        return [
            'id' => $po->id,
            'poNo' => $po->po_no,
            'supplierId' => $po->supplier_id,
            'orderDate' => $po->order_date?->format('Y-m-d'),
            'expectedDate' => $po->expected_date?->format('Y-m-d'),
            'currency' => $po->currency,
            'status' => $po->status,
            'netMinor' => $po->net_minor,
            'taxTotalMinor' => $po->tax_total_minor,
            'grossMinor' => $po->gross_minor,
            'memo' => $po->memo,
            'lines' => $po->lines->map(static fn (PurchaseOrderLine $l) => [
                'id' => $l->id,
                'lineNo' => $l->line_no,
                'itemId' => $l->item_id,
                'salesOrderLineId' => $l->sales_order_line_id,
                'description' => $l->description,
                'qty' => (string) $l->qty,
                'unit' => $l->unit,
                'unitPriceMinor' => $l->unit_price_minor,
                'netMinor' => $l->net_minor,
                'taxMinor' => $l->tax_minor,
                'grossMinor' => $l->gross_minor,
                'qtyReceived' => (string) $l->qty_received,
                'lineCategory' => $l->line_category,
            ])->all(),
            'createdAt' => $po->created_at?->toIso8601String(),
        ];
    }
}
