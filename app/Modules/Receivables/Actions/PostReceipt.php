<?php

declare(strict_types=1);

namespace App\Modules\Receivables\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Invoice;
use App\Models\NumberSequence;
use App\Models\Party;
use App\Models\Receipt;
use App\Models\ReceiptAllocation;
use App\Modules\Ledger\Domain\PostingKey;
use App\Modules\Ledger\Domain\PostingLine;
use App\Modules\Ledger\Domain\PostingRequest;
use App\Modules\Ledger\Services\PostingService;
use App\Modules\Receivables\Services\AccountResolver;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Outbox\OutboxWriter;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/receipts — post a customer receipt with optional allocations.
 */
final class PostReceipt
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
        $this->permissionChecker->assertCan($actorId, 'ar.receipt.post');

        $companyId = $this->companyContext->companyId();
        $customerId = (string) ($data['customerId'] ?? '');
        $receiptDate = (string) ($data['date'] ?? $data['receiptDate'] ?? '');
        $cashMinor = (int) ($data['cashMinor'] ?? 0);
        $whtMinor = (int) ($data['whtMinor'] ?? 0);
        $whtCertificate = isset($data['whtCertificate']) ? trim((string) $data['whtCertificate']) : null;
        $cashAccountCode = (string) ($data['cashAccount'] ?? $data['cashAccountCode'] ?? '1000');
        $bankRef = isset($data['bankRef']) ? trim((string) $data['bankRef']) : null;
        $rawAllocations = $data['allocations'] ?? [];
        $memo = isset($data['memo']) ? trim((string) $data['memo']) : null;
        $requestId = $this->companyContext->requestId();

        if ($customerId === '') {
            throw new BusinessRuleException('customerId is required.');
        }
        if ($receiptDate === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $receiptDate)) {
            throw new BusinessRuleException('date must be YYYY-MM-DD.');
        }
        if ($cashMinor < 0 || $whtMinor < 0) {
            throw new BusinessRuleException('cashMinor and whtMinor cannot be negative.');
        }
        $totalMinor = $cashMinor + $whtMinor;
        if ($totalMinor <= 0) {
            throw new BusinessRuleException('Receipt total must be positive.');
        }
        if ($whtMinor > 0 && ($whtCertificate === null || $whtCertificate === '')) {
            throw new BusinessRuleException('Withholding requires a certificate reference.');
        }

        $customer = Party::query()
            ->where('company_id', $companyId)
            ->where('id', $customerId)
            ->where('party_type', 'customer')
            ->where('is_active', true)
            ->first();
        if ($customer === null) {
            throw new NotFoundException('Customer not found.');
        }

        if (! is_array($rawAllocations)) {
            throw new BusinessRuleException('allocations must be an array.');
        }

        $allocSum = 0;
        $resolvedAllocs = [];
        foreach ($rawAllocations as $i => $raw) {
            if (! is_array($raw)) {
                throw new BusinessRuleException("Allocation {$i} is invalid.");
            }
            $invoiceId = (string) ($raw['invoiceId'] ?? '');
            $amount = (int) ($raw['amountMinor'] ?? 0);
            if ($invoiceId === '' || $amount <= 0) {
                throw new BusinessRuleException("Allocation {$i} requires invoiceId and positive amountMinor.");
            }
            $invoice = Invoice::query()
                ->where('company_id', $companyId)
                ->where('id', $invoiceId)
                ->where('customer_id', $customerId)
                ->where('status', 'posted')
                ->first();
            if ($invoice === null) {
                throw new NotFoundException("Invoice {$invoiceId} not found for this customer.");
            }
            $open = (int) DB::selectOne('SELECT invoice_open(?::uuid, ?::date) AS open', [$invoiceId, $receiptDate])->open;
            if ($amount > $open) {
                throw new BusinessRuleException("Allocation to invoice {$invoice->invoice_no} exceeds open balance ({$open}).");
            }
            $resolvedAllocs[] = ['invoiceId' => $invoiceId, 'amountMinor' => $amount];
            $allocSum += $amount;
        }

        if ($allocSum > $totalMinor) {
            throw new BusinessRuleException('Allocations exceed receipt total.');
        }

        $currency = strtoupper((string) ($data['currency'] ?? $customer->currency ?? 'GHS'));

        return DB::transaction(function () use (
            $companyId,
            $customerId,
            $receiptDate,
            $cashMinor,
            $whtMinor,
            $whtCertificate,
            $totalMinor,
            $cashAccountCode,
            $bankRef,
            $resolvedAllocs,
            $allocSum,
            $currency,
            $memo,
            $actorId,
            $requestId,
        ) {
            $receiptId = Uuid::v7();
            $receiptNo = $this->nextNumber($companyId, $receiptDate, 'receipt');

            $receipt = new Receipt;
            $receipt->id = $receiptId;
            $receipt->company_id = $companyId;
            $receipt->receipt_no = $receiptNo;
            $receipt->customer_id = $customerId;
            $receipt->receipt_date = $receiptDate;
            $receipt->currency = $currency;
            $receipt->cash_account_code = $cashAccountCode;
            $receipt->cash_minor = $cashMinor;
            $receipt->wht_minor = $whtMinor;
            $receipt->wht_certificate = $whtCertificate;
            $receipt->total_minor = $totalMinor;
            $receipt->bank_ref = $bankRef;
            $receipt->memo = $memo;
            $receipt->created_by = $actorId;
            $receipt->request_id = $requestId;
            $receipt->save();

            foreach ($resolvedAllocs as $alloc) {
                $row = new ReceiptAllocation;
                $row->company_id = $companyId;
                $row->receipt_id = $receiptId;
                $row->invoice_id = $alloc['invoiceId'];
                $row->amount_minor = $alloc['amountMinor'];
                $row->allocated_at = $receiptDate;
                $row->created_by = $actorId;
                $row->save();
            }

            // Residual becomes customer advance (tracked by receipt_available)
            $remainder = $totalMinor - $allocSum;

            // Journal: Dr cash, Dr WHT receivable; Cr AR (allocated), Cr advances (remainder)
            $postingLines = [];
            if ($cashMinor > 0) {
                $postingLines[] = new PostingLine(
                    accountId: $this->accountResolver->idByCashCode($companyId, $cashAccountCode),
                    debitMinor: $cashMinor,
                    creditMinor: 0,
                    partyId: $customerId,
                    memo: "Receipt {$receiptNo}",
                );
            }
            if ($whtMinor > 0) {
                $postingLines[] = new PostingLine(
                    accountId: $this->accountResolver->idByCode($companyId, 'wht_receivable'),
                    debitMinor: $whtMinor,
                    creditMinor: 0,
                    partyId: $customerId,
                    memo: "WHT {$receiptNo}",
                );
            }
            if ($allocSum > 0) {
                $postingLines[] = new PostingLine(
                    accountId: $this->accountResolver->idByCode($companyId, 'ar'),
                    debitMinor: 0,
                    creditMinor: $allocSum,
                    partyId: $customerId,
                    memo: "Receipt {$receiptNo} applied",
                );
            }
            if ($remainder > 0) {
                $postingLines[] = new PostingLine(
                    accountId: $this->accountResolver->idByCode($companyId, 'advances'),
                    debitMinor: 0,
                    creditMinor: $remainder,
                    partyId: $customerId,
                    memo: "Receipt {$receiptNo} advance",
                );
            }

            $journalId = $this->postingService->post(new PostingRequest(
                companyId: $companyId,
                postingDate: $receiptDate,
                postingKey: PostingKey::make('receipt', $receiptId, 'post'),
                sourceType: 'receipt',
                sourceId: $receiptId,
                eventType: 'post',
                description: "Receipt {$receiptNo}",
                lines: $postingLines,
                actorId: $actorId,
                requestId: $requestId,
                currency: $currency,
            ));

            $receipt->journal_id = $journalId;
            $receipt->save();

            $this->auditWriter->write(
                action: 'receipt.posted',
                entityType: 'receipt',
                entityId: $receiptId,
                detail: [
                    'receiptNo' => $receiptNo,
                    'customerId' => $customerId,
                    'totalMinor' => $totalMinor,
                    'allocatedMinor' => $allocSum,
                    'availableMinor' => $remainder,
                    'journalId' => $journalId,
                ],
                actorId: $actorId,
            );

            $this->outboxWriter->record([
                'aggregate_type' => 'receipt',
                'aggregate_id' => $receiptId,
                'event_type' => 'receipt.posted',
                'payload' => [
                    'receiptId' => $receiptId,
                    'receiptNo' => $receiptNo,
                    'customerId' => $customerId,
                ],
            ]);

            return $this->toArray(Receipt::query()->with('allocations')->findOrFail($receiptId), $remainder);
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
            $seq->prefix = 'RCT';
            $seq->save();
        }

        $value = $seq->next_value;
        $seq->next_value = $value + 1;
        $seq->save();

        return sprintf('RCT-%s-%05d', $year, $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(Receipt $receipt, int $availableMinor): array
    {
        return [
            'id' => $receipt->id,
            'receiptNo' => $receipt->receipt_no,
            'customerId' => $receipt->customer_id,
            'receiptDate' => $receipt->receipt_date?->format('Y-m-d'),
            'currency' => $receipt->currency,
            'cashAccountCode' => $receipt->cash_account_code,
            'cashMinor' => $receipt->cash_minor,
            'whtMinor' => $receipt->wht_minor,
            'whtCertificate' => $receipt->wht_certificate,
            'totalMinor' => $receipt->total_minor,
            'availableMinor' => $availableMinor,
            'bankRef' => $receipt->bank_ref,
            'journalId' => $receipt->journal_id,
            'memo' => $receipt->memo,
            'allocations' => $receipt->allocations->map(static fn (ReceiptAllocation $a) => [
                'invoiceId' => $a->invoice_id,
                'amountMinor' => $a->amount_minor,
                'allocatedAt' => $a->allocated_at?->format('Y-m-d'),
            ])->values()->all(),
            'createdAt' => $receipt->created_at?->toIso8601String(),
        ];
    }
}
