<?php

declare(strict_types=1);

namespace App\Modules\Payables\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\NumberSequence;
use App\Models\Party;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Modules\Ledger\Domain\PostingKey;
use App\Modules\Ledger\Domain\PostingLine;
use App\Modules\Ledger\Domain\PostingRequest;
use App\Modules\Ledger\Services\PostingService;
use App\Modules\Payables\Services\AccountResolver;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Outbox\OutboxWriter;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/bills — post a supplier bill (one journal).
 */
final class PostBill
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly PostingService $postingService,
        private readonly AccountResolver $accountResolver,
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
        $this->permissionChecker->assertCan($actorId, 'ap.bill.post');

        $companyId = $this->companyContext->companyId();
        $supplierId = (string) ($data['supplierId'] ?? '');
        $billDate = (string) ($data['billDate'] ?? $data['date'] ?? '');
        $supplierRef = isset($data['supplierRef']) ? trim((string) $data['supplierRef']) : null;
        $netMinor = (int) ($data['netMinor'] ?? 0);
        $taxMinor = (int) ($data['taxMinor'] ?? 0);
        $grossMinor = (int) ($data['grossMinor'] ?? ($netMinor + $taxMinor));
        $expenseAccountId = isset($data['expenseAccountId']) ? (string) $data['expenseAccountId'] : null;
        $expenseAccountCode = (string) ($data['expenseAccountCode'] ?? '5000');
        $memo = isset($data['memo']) ? trim((string) $data['memo']) : null;
        $dueDate = isset($data['dueDate']) ? (string) $data['dueDate'] : null;
        $requestId = $this->companyContext->requestId();

        if ($supplierId === '') {
            throw new BusinessRuleException('supplierId is required.');
        }
        if ($billDate === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $billDate)) {
            throw new BusinessRuleException('billDate must be YYYY-MM-DD.');
        }
        if ($grossMinor <= 0) {
            throw new BusinessRuleException('grossMinor must be positive.');
        }
        if ($netMinor + $taxMinor !== $grossMinor) {
            throw new BusinessRuleException('grossMinor must equal netMinor + taxMinor.');
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

        if ($supplierRef !== null && $supplierRef !== '') {
            $dup = Bill::query()
                ->where('company_id', $companyId)
                ->where('supplier_id', $supplierId)
                ->where('supplier_ref', $supplierRef)
                ->exists();
            if ($dup) {
                throw new ConflictException("Duplicate supplier reference '{$supplierRef}' for this supplier.");
            }
        }

        $currency = strtoupper((string) ($data['currency'] ?? $supplier->currency ?? 'GHS'));

        return DB::transaction(function () use (
            $companyId,
            $supplierId,
            $billDate,
            $dueDate,
            $supplierRef,
            $netMinor,
            $taxMinor,
            $grossMinor,
            $expenseAccountId,
            $expenseAccountCode,
            $currency,
            $memo,
            $actorId,
            $requestId,
            $data,
        ) {
            $billId = Uuid::v7();
            $billNo = $this->nextNumber($companyId, $billDate, 'bill');

            $resolvedExpenseId = $expenseAccountId;
            if ($resolvedExpenseId === null || $resolvedExpenseId === '') {
                $resolvedExpenseId = $this->accountResolver->idByCode($companyId, $expenseAccountCode);
            }

            $bill = new Bill;
            $bill->id = $billId;
            $bill->company_id = $companyId;
            $bill->bill_no = $billNo;
            $bill->supplier_id = $supplierId;
            $bill->supplier_ref = $supplierRef;
            $bill->purchase_order_id = isset($data['purchaseOrderId']) ? (string) $data['purchaseOrderId'] : null;
            $bill->bill_date = $billDate;
            $bill->due_date = $dueDate;
            $bill->currency = $currency;
            $bill->net_minor = $netMinor;
            $bill->tax_minor = $taxMinor;
            $bill->gross_minor = $grossMinor;
            $bill->expense_account_id = $resolvedExpenseId;
            $status = (string) ($data['status'] ?? 'posted');
            if (! in_array($status, ['posted', 'mismatch'], true)) {
                $status = 'posted';
            }
            $bill->status = $status;
            $bill->memo = $memo;
            $bill->created_by = $actorId;
            $bill->request_id = $requestId;
            $bill->save();

            $this->persistBillLines($companyId, $billId, $supplierId, $data);

            // Dr expense (net), Dr claimable tax, Cr AP (gross)
            $lines = [
                new PostingLine(
                    accountId: $resolvedExpenseId,
                    debitMinor: $netMinor,
                    creditMinor: 0,
                    partyId: $supplierId,
                    memo: "Bill {$billNo}",
                ),
            ];
            if ($taxMinor > 0) {
                $lines[] = new PostingLine(
                    accountId: $this->accountResolver->idByCode($companyId, 'claimable_tax'),
                    debitMinor: $taxMinor,
                    creditMinor: 0,
                    memo: "Tax on bill {$billNo}",
                );
            }
            $lines[] = new PostingLine(
                accountId: $this->accountResolver->idByCode($companyId, 'ap'),
                debitMinor: 0,
                creditMinor: $grossMinor,
                partyId: $supplierId,
                memo: "Bill {$billNo}",
            );

            $journalId = $this->postingService->post(new PostingRequest(
                companyId: $companyId,
                postingDate: $billDate,
                postingKey: PostingKey::make('bill', $billId, 'post'),
                sourceType: 'bill',
                sourceId: $billId,
                eventType: 'post',
                description: "Bill {$billNo}",
                lines: $lines,
                actorId: $actorId,
                requestId: $requestId,
                currency: $currency,
            ));

            $bill->journal_id = $journalId;
            $bill->save();

            $this->auditWriter->write(
                action: 'bill.posted',
                entityType: 'bill',
                entityId: $billId,
                detail: [
                    'billNo' => $billNo,
                    'supplierId' => $supplierId,
                    'grossMinor' => $grossMinor,
                    'journalId' => $journalId,
                ],
                actorId: $actorId,
            );

            $this->outboxWriter->record([
                'aggregate_type' => 'bill',
                'aggregate_id' => $billId,
                'event_type' => 'bill.posted',
                'payload' => [
                    'billId' => $billId,
                    'billNo' => $billNo,
                    'supplierId' => $supplierId,
                ],
            ]);

            return [
                'id' => $bill->id,
                'billNo' => $bill->bill_no,
                'supplierId' => $bill->supplier_id,
                'supplierRef' => $bill->supplier_ref,
                'billDate' => $bill->bill_date?->format('Y-m-d'),
                'dueDate' => $bill->due_date?->format('Y-m-d'),
                'currency' => $bill->currency,
                'netMinor' => $bill->net_minor,
                'taxMinor' => $bill->tax_minor,
                'grossMinor' => $bill->gross_minor,
                'status' => $bill->status,
                'journalId' => $bill->journal_id,
                'memo' => $bill->memo,
                'createdAt' => $bill->created_at?->toIso8601String(),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persistBillLines(string $companyId, string $billId, string $supplierId, array $data): void
    {
        $rawLines = $data['lines'] ?? null;
        if (! is_array($rawLines) || $rawLines === []) {
            return;
        }

        $headerPoId = isset($data['purchaseOrderId']) ? (string) $data['purchaseOrderId'] : null;

        foreach ($rawLines as $index => $raw) {
            if (! is_array($raw)) {
                throw new BusinessRuleException("Bill line {$index} is invalid.");
            }

            $poLineId = isset($raw['purchaseOrderLineId']) ? (string) $raw['purchaseOrderLineId'] : null;
            $itemId = isset($raw['itemId']) ? (string) $raw['itemId'] : null;
            $description = trim((string) ($raw['description'] ?? ''));
            $qty = (string) ($raw['qty'] ?? '1');
            $unit = (string) ($raw['unit'] ?? 'pcs');
            $unitPrice = (int) ($raw['unitPriceMinor'] ?? 0);
            $net = (int) ($raw['netMinor'] ?? 0);
            $tax = (int) ($raw['taxMinor'] ?? 0);
            $gross = (int) ($raw['grossMinor'] ?? ($net + $tax));

            if ($poLineId !== null && $poLineId !== '') {
                $poLine = PurchaseOrderLine::query()
                    ->where('company_id', $companyId)
                    ->where('id', $poLineId)
                    ->first();
                if ($poLine === null) {
                    throw new NotFoundException("Purchase order line not found for bill line {$index}.");
                }

                $po = PurchaseOrder::query()
                    ->where('company_id', $companyId)
                    ->where('id', $poLine->purchase_order_id)
                    ->first();
                if ($po === null) {
                    throw new NotFoundException("Purchase order not found for bill line {$index}.");
                }
                if ($po->supplier_id !== $supplierId) {
                    throw new BusinessRuleException(
                        "Bill line {$index}: purchase order line belongs to a different supplier."
                    );
                }
                if ($headerPoId !== null && $headerPoId !== '' && $po->id !== $headerPoId) {
                    throw new BusinessRuleException(
                        "Bill line {$index}: purchase order line does not belong to bill purchaseOrderId."
                    );
                }
                if ($description === '') {
                    $description = (string) $poLine->description;
                }
                if ($itemId === null || $itemId === '') {
                    $itemId = $poLine->item_id;
                }
            }

            if ($description === '') {
                throw new BusinessRuleException("Bill line {$index}: description is required.");
            }

            $line = new BillLine;
            $line->fill([
                'id' => Uuid::v7(),
                'company_id' => $companyId,
                'bill_id' => $billId,
                'line_no' => $index + 1,
                'purchase_order_line_id' => $poLineId ?: null,
                'item_id' => $itemId ?: null,
                'description' => $description,
                'qty' => $qty,
                'unit' => $unit,
                'unit_price_minor' => $unitPrice,
                'net_minor' => $net,
                'tax_minor' => $tax,
                'gross_minor' => $gross,
            ]);
            $line->save();
        }
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
            $seq->prefix = 'BILL';
            $seq->save();
        }

        $value = $seq->next_value;
        $seq->next_value = $value + 1;
        $seq->save();

        return sprintf('BILL-%s-%05d', $year, $value);
    }
}
