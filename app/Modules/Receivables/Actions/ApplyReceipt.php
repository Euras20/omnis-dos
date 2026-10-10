<?php

declare(strict_types=1);

namespace App\Modules\Receivables\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Invoice;
use App\Models\Receipt;
use App\Models\ReceiptAllocation;
use App\Modules\Ledger\Domain\PostingKey;
use App\Modules\Ledger\Domain\PostingLine;
use App\Modules\Ledger\Domain\PostingRequest;
use App\Modules\Ledger\Services\PostingService;
use App\Modules\Receivables\Services\AccountResolver;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/receipts/{id}/apply — apply unapplied advance to invoices.
 */
final class ApplyReceipt
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly PostingService $postingService,
        private readonly AccountResolver $accountResolver,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function execute(string $receiptId, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'ar.receipt.post');

        $companyId = $this->companyContext->companyId();
        $rawAllocations = $data['allocations'] ?? [];
        $applyDate = (string) ($data['date'] ?? date('Y-m-d'));
        $requestId = $this->companyContext->requestId();

        if (! is_array($rawAllocations) || $rawAllocations === []) {
            throw new BusinessRuleException('allocations are required.');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $applyDate)) {
            throw new BusinessRuleException('date must be YYYY-MM-DD.');
        }

        $receipt = Receipt::query()
            ->where('company_id', $companyId)
            ->where('id', $receiptId)
            ->first();
        if ($receipt === null) {
            throw new NotFoundException('Receipt not found.');
        }

        $available = (int) DB::selectOne('SELECT receipt_available(?::uuid, ?::date) AS avail', [$receiptId, $applyDate])->avail;
        if ($available <= 0) {
            throw new BusinessRuleException('Receipt has no unapplied balance.');
        }

        $allocSum = 0;
        $resolved = [];
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
                ->where('customer_id', $receipt->customer_id)
                ->where('status', 'posted')
                ->first();
            if ($invoice === null) {
                throw new NotFoundException("Invoice {$invoiceId} not found.");
            }
            $open = (int) DB::selectOne('SELECT invoice_open(?::uuid, ?::date) AS open', [$invoiceId, $applyDate])->open;
            if ($amount > $open) {
                throw new BusinessRuleException("Allocation exceeds open balance of invoice {$invoice->invoice_no}.");
            }
            $resolved[] = ['invoiceId' => $invoiceId, 'amountMinor' => $amount];
            $allocSum += $amount;
        }

        if ($allocSum > $available) {
            throw new BusinessRuleException("Allocations ({$allocSum}) exceed available balance ({$available}).");
        }

        return DB::transaction(function () use (
            $companyId,
            $receipt,
            $receiptId,
            $resolved,
            $allocSum,
            $applyDate,
            $available,
            $actorId,
            $requestId,
        ) {
            foreach ($resolved as $alloc) {
                $row = new ReceiptAllocation;
                $row->company_id = $companyId;
                $row->receipt_id = $receiptId;
                $row->invoice_id = $alloc['invoiceId'];
                $row->amount_minor = $alloc['amountMinor'];
                $row->allocated_at = $applyDate;
                $row->created_by = $actorId;
                $row->save();
            }

            // Dr advances, Cr AR
            $lines = [
                new PostingLine(
                    accountId: $this->accountResolver->idByCode($companyId, 'advances'),
                    debitMinor: $allocSum,
                    creditMinor: 0,
                    partyId: $receipt->customer_id,
                    memo: "Apply advance {$receipt->receipt_no}",
                ),
                new PostingLine(
                    accountId: $this->accountResolver->idByCode($companyId, 'ar'),
                    debitMinor: 0,
                    creditMinor: $allocSum,
                    partyId: $receipt->customer_id,
                    memo: "Apply advance {$receipt->receipt_no}",
                ),
            ];

            $applyKey = Uuid::v7();
            $this->postingService->post(new PostingRequest(
                companyId: $companyId,
                postingDate: $applyDate,
                postingKey: new PostingKey("receipt:{$receiptId}:apply:{$applyKey}"),
                sourceType: 'receipt',
                sourceId: $receiptId,
                eventType: 'apply',
                description: "Apply advance from {$receipt->receipt_no}",
                lines: $lines,
                actorId: $actorId,
                requestId: $requestId,
                currency: $receipt->currency,
            ));

            $this->auditWriter->write(
                action: 'receipt.applied',
                entityType: 'receipt',
                entityId: $receiptId,
                detail: [
                    'allocatedMinor' => $allocSum,
                    'remainingAvailable' => $available - $allocSum,
                ],
                actorId: $actorId,
            );

            $newAvailable = $available - $allocSum;

            return [
                'receiptId' => $receiptId,
                'allocatedMinor' => $allocSum,
                'availableMinor' => $newAvailable,
            ];
        });
    }
}
