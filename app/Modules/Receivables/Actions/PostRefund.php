<?php

declare(strict_types=1);

namespace App\Modules\Receivables\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\CustomerRefund;
use App\Models\NumberSequence;
use App\Models\Receipt;
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
 * POST /api/v1/receipts/{id}/refunds — refund unapplied customer money.
 */
final class PostRefund
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
        $this->permissionChecker->assertCan($actorId, 'ar.refund.post');

        $companyId = $this->companyContext->companyId();
        $refundDate = (string) ($data['date'] ?? $data['refundDate'] ?? '');
        $amountMinor = (int) ($data['amountMinor'] ?? 0);
        $cashAccountCode = (string) ($data['cashAccount'] ?? $data['cashAccountCode'] ?? '1000');
        $bankRef = isset($data['bankRef']) ? trim((string) $data['bankRef']) : null;
        $requestId = $this->companyContext->requestId();

        if ($refundDate === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $refundDate)) {
            throw new BusinessRuleException('date must be YYYY-MM-DD.');
        }
        if ($amountMinor <= 0) {
            throw new BusinessRuleException('amountMinor must be positive.');
        }

        $receipt = Receipt::query()
            ->where('company_id', $companyId)
            ->where('id', $receiptId)
            ->first();
        if ($receipt === null) {
            throw new NotFoundException('Receipt not found.');
        }

        $available = (int) DB::selectOne('SELECT receipt_available(?::uuid, ?::date) AS avail', [$receiptId, $refundDate])->avail;
        if ($amountMinor > $available) {
            throw new BusinessRuleException("Refund amount exceeds available balance ({$available}).");
        }

        return DB::transaction(function () use (
            $companyId,
            $receipt,
            $receiptId,
            $refundDate,
            $amountMinor,
            $cashAccountCode,
            $bankRef,
            $actorId,
            $requestId,
        ) {
            $refundId = Uuid::v7();
            $refundNo = $this->nextNumber($companyId, $refundDate, 'refund');

            $refund = new CustomerRefund;
            $refund->id = $refundId;
            $refund->company_id = $companyId;
            $refund->receipt_id = $receiptId;
            $refund->refund_no = $refundNo;
            $refund->refund_date = $refundDate;
            $refund->amount_minor = $amountMinor;
            $refund->cash_account_code = $cashAccountCode;
            $refund->bank_ref = $bankRef;
            $refund->created_by = $actorId;
            $refund->request_id = $requestId;
            $refund->save();

            // Dr advances, Cr cash
            $lines = [
                new PostingLine(
                    accountId: $this->accountResolver->idByCode($companyId, 'advances'),
                    debitMinor: $amountMinor,
                    creditMinor: 0,
                    partyId: $receipt->customer_id,
                    memo: "Refund {$refundNo}",
                ),
                new PostingLine(
                    accountId: $this->accountResolver->idByCashCode($companyId, $cashAccountCode),
                    debitMinor: 0,
                    creditMinor: $amountMinor,
                    partyId: $receipt->customer_id,
                    memo: "Refund {$refundNo}",
                ),
            ];

            $journalId = $this->postingService->post(new PostingRequest(
                companyId: $companyId,
                postingDate: $refundDate,
                postingKey: PostingKey::make('refund', $refundId, 'post'),
                sourceType: 'refund',
                sourceId: $refundId,
                eventType: 'post',
                description: "Customer refund {$refundNo}",
                lines: $lines,
                actorId: $actorId,
                requestId: $requestId,
                currency: $receipt->currency,
            ));

            $refund->journal_id = $journalId;
            $refund->save();

            $this->auditWriter->write(
                action: 'refund.posted',
                entityType: 'customer_refund',
                entityId: $refundId,
                detail: [
                    'refundNo' => $refundNo,
                    'receiptId' => $receiptId,
                    'amountMinor' => $amountMinor,
                    'journalId' => $journalId,
                ],
                actorId: $actorId,
            );

            return [
                'id' => $refund->id,
                'refundNo' => $refund->refund_no,
                'receiptId' => $receiptId,
                'refundDate' => $refund->refund_date?->format('Y-m-d'),
                'amountMinor' => $refund->amount_minor,
                'cashAccountCode' => $refund->cash_account_code,
                'bankRef' => $refund->bank_ref,
                'journalId' => $refund->journal_id,
            ];
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
            $seq->prefix = 'RFD';
            $seq->save();
        }

        $value = $seq->next_value;
        $seq->next_value = $value + 1;
        $seq->save();

        return sprintf('RFD-%s-%05d', $year, $value);
    }
}
