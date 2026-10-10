<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Account;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Location;
use App\Models\NumberSequence;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Modules\Inventory\Domain\MovementRequest;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Services\MovementService;
use App\Modules\Ledger\Domain\PostingKey;
use App\Modules\Ledger\Domain\PostingLine;
use App\Modules\Ledger\Domain\PostingRequest;
use App\Modules\Ledger\Services\PostingService;
use App\Modules\Procurement\Domain\PurchaseOrderStatus;
use App\Modules\Procurement\Domain\QcStatus;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Outbox\OutboxWriter;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/goods-receipts — receive goods against an approved PO into a receiving location.
 * Dr inventory (1300/1340), Cr GRNI (2050); records stock_movement via Inventory\MovementService.
 */
final class PostGoodsReceipt
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly MovementService $movementService,
        private readonly PostingService $postingService,
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
        $this->permissionChecker->assertCan($actorId, 'stock.move');

        $companyId = $this->companyContext->companyId();
        $purchaseOrderId = (string) ($data['purchaseOrderId'] ?? '');
        $locationId = (string) ($data['locationId'] ?? '');
        $receiptDate = (string) ($data['receiptDate'] ?? $data['date'] ?? '');
        $rawLines = $data['lines'] ?? [];
        $memo = isset($data['memo']) ? trim((string) $data['memo']) : null;
        $requestId = $this->companyContext->requestId();

        if ($purchaseOrderId === '') {
            throw new BusinessRuleException('purchaseOrderId is required.');
        }
        if ($locationId === '') {
            throw new BusinessRuleException('locationId is required.');
        }
        if ($receiptDate === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $receiptDate)) {
            throw new BusinessRuleException('receiptDate must be YYYY-MM-DD.');
        }
        if (! is_array($rawLines) || $rawLines === []) {
            throw new BusinessRuleException('At least one receipt line is required.');
        }

        $location = Location::query()
            ->where('company_id', $companyId)
            ->where('id', $locationId)
            ->where('is_active', true)
            ->first();
        if ($location === null) {
            throw new NotFoundException('Receiving location not found.');
        }

        return DB::transaction(function () use (
            $companyId,
            $purchaseOrderId,
            $locationId,
            $receiptDate,
            $rawLines,
            $memo,
            $actorId,
            $requestId,
        ) {
            $po = PurchaseOrder::query()
                ->with('lines')
                ->where('company_id', $companyId)
                ->where('id', $purchaseOrderId)
                ->lockForUpdate()
                ->first();

            if ($po === null) {
                throw new NotFoundException('Purchase order not found.');
            }
            if ($po->status !== PurchaseOrderStatus::APPROVED) {
                throw new BusinessRuleException('Goods can only be received against an approved purchase order.');
            }

            $poLinesById = $po->lines->keyBy('id');
            $grnId = Uuid::v7();
            $grnNo = $this->nextNumber($companyId, $receiptDate, 'goods_receipt');

            $inventoryAccount = $this->accountByCode($companyId, '1300');
            $grniAccount = $this->accountByCode($companyId, '2050');

            $postingLines = [];
            $lineRows = [];
            $totalValue = 0;
            $lineNo = 0;

            foreach ($rawLines as $index => $raw) {
                if (! is_array($raw)) {
                    throw new BusinessRuleException("Line {$index} is invalid.");
                }
                $poLineId = (string) ($raw['purchaseOrderLineId'] ?? '');
                $qtyReceived = (string) ($raw['qtyReceived'] ?? $raw['qty'] ?? '');
                $unitCost = (int) ($raw['unitCostMinor'] ?? $raw['unit_cost_minor'] ?? 0);
                $lot = isset($raw['lot']) ? trim((string) $raw['lot']) : null;
                if ($lot === '') {
                    $lot = null;
                }

                if ($poLineId === '' || ! $poLinesById->has($poLineId)) {
                    throw new BusinessRuleException("Line {$index}: purchaseOrderLineId is invalid for this PO.");
                }
                if ($qtyReceived === '' || ! is_numeric($qtyReceived) || (float) $qtyReceived <= 0) {
                    throw new BusinessRuleException("Line {$index}: qtyReceived must be positive.");
                }
                if ($unitCost < 0) {
                    throw new BusinessRuleException("Line {$index}: unitCostMinor cannot be negative.");
                }

                /** @var PurchaseOrderLine $poLine */
                $poLine = $poLinesById->get($poLineId);
                if ($poLine->item_id === null) {
                    throw new BusinessRuleException("Line {$index}: PO line has no item; cannot receive stock.");
                }

                $remaining = (float) $poLine->qty - (float) $poLine->qty_received;
                if ((float) $qtyReceived > $remaining + 0.0005) {
                    throw new BusinessRuleException(
                        "Line {$index}: qtyReceived exceeds open PO quantity ({$remaining})."
                    );
                }

                $lineNo++;
                $lineId = Uuid::v7();
                $lineValue = (int) round((float) $qtyReceived * $unitCost);
                $totalValue += $lineValue;

                $lineRows[] = [
                    'id' => $lineId,
                    'company_id' => $companyId,
                    'goods_receipt_id' => $grnId,
                    'purchase_order_line_id' => $poLineId,
                    'line_no' => $lineNo,
                    'item_id' => $poLine->item_id,
                    'qty_received' => $qtyReceived,
                    'qty_certified' => 0,
                    'qty_quarantine' => 0,
                    'qty_rejected' => 0,
                    'lot' => $lot,
                    'unit_cost_minor' => $unitCost,
                    'qc_status' => QcStatus::PENDING,
                    'value' => $lineValue,
                    'po_line' => $poLine,
                ];
            }

            if ($totalValue <= 0) {
                throw new BusinessRuleException('Goods receipt total value must be positive.');
            }

            $postingKey = PostingKey::make('goods_receipt', $grnId, 'receive');
            $journalId = $this->postingService->post(new PostingRequest(
                companyId: $companyId,
                postingDate: $receiptDate,
                postingKey: $postingKey,
                sourceType: 'goods_receipt',
                sourceId: $grnId,
                eventType: 'receive',
                description: $memo ?? "GRN {$grnNo}",
                lines: [
                    new PostingLine(
                        accountId: $inventoryAccount->id,
                        debitMinor: $totalValue,
                        creditMinor: 0,
                        partyId: null,
                        memo: 'Inventory receipt',
                    ),
                    new PostingLine(
                        accountId: $grniAccount->id,
                        debitMinor: 0,
                        creditMinor: $totalValue,
                        partyId: $po->supplier_id,
                        memo: 'GRNI',
                    ),
                ],
                actorId: $actorId,
                requestId: $requestId,
                preparedBy: $actorId,
            ));

            $grn = new GoodsReceipt;
            $grn->fill([
                'id' => $grnId,
                'company_id' => $companyId,
                'grn_no' => $grnNo,
                'purchase_order_id' => $purchaseOrderId,
                'location_id' => $locationId,
                'receipt_date' => $receiptDate,
                'status' => 'received',
                'journal_id' => $journalId,
                'memo' => $memo,
                'created_by' => $actorId,
                'request_id' => $requestId,
            ]);
            $grn->save();

            foreach ($lineRows as $row) {
                /** @var PurchaseOrderLine $poLine */
                $poLine = $row['po_line'];
                unset($row['po_line'], $row['value']);

                $movementId = $this->movementService->record(new MovementRequest(
                    companyId: $companyId,
                    movementType: MovementType::RECEIPT,
                    itemId: $row['item_id'],
                    locationId: $locationId,
                    lot: $row['lot'],
                    qty: $row['qty_received'],
                    unitCostMinor: $row['unit_cost_minor'],
                    movementDate: $receiptDate,
                    sourceType: 'goods_receipt',
                    sourceId: $grnId,
                    salesOrderLineId: $poLine->sales_order_line_id,
                    goodsReceiptLineId: $row['id'],
                    stockAssignmentId: null,
                    journalId: $journalId,
                    memo: $memo,
                    actorId: $actorId,
                    requestId: $requestId,
                ));

                $row['stock_movement_id'] = $movementId;
                $grLine = new GoodsReceiptLine;
                $grLine->fill($row);
                $grLine->save();

                $poLine->qty_received = (string) ((float) $poLine->qty_received + (float) $row['qty_received']);
                $poLine->save();
            }

            $this->auditWriter->write(
                action: 'goods_receipt.posted',
                entityType: 'goods_receipt',
                entityId: $grnId,
                detail: [
                    'grnNo' => $grnNo,
                    'purchaseOrderId' => $purchaseOrderId,
                    'journalId' => $journalId,
                    'totalValueMinor' => $totalValue,
                    'lineCount' => count($lineRows),
                ],
                actorId: $actorId,
            );

            $this->outboxWriter->record([
                'aggregate_type' => 'goods_receipt',
                'aggregate_id' => $grnId,
                'event_type' => 'goods_receipt.posted',
                'payload' => [
                    'goodsReceiptId' => $grnId,
                    'grnNo' => $grnNo,
                    'purchaseOrderId' => $purchaseOrderId,
                    'journalId' => $journalId,
                ],
            ]);

            return [
                'id' => $grnId,
                'grnNo' => $grnNo,
                'purchaseOrderId' => $purchaseOrderId,
                'locationId' => $locationId,
                'receiptDate' => $receiptDate,
                'status' => 'received',
                'journalId' => $journalId,
                'lineCount' => count($lineRows),
            ];
        });
    }

    private function accountByCode(string $companyId, string $code): Account
    {
        $account = Account::query()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if ($account === null) {
            throw new BusinessRuleException("Required account code {$code} is missing or inactive.");
        }

        return $account;
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
            $seq->prefix = 'GRN';
            $seq->save();
        }

        $value = $seq->next_value;
        $seq->next_value = $value + 1;
        $seq->save();

        $prefix = $seq->prefix ?: 'GRN';

        return sprintf('%s-%s-%05d', $prefix, $year, $value);
    }
}
