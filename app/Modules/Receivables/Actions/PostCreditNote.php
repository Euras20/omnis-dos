<?php

declare(strict_types=1);

namespace App\Modules\Receivables\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\NumberSequence;
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
 * POST /api/v1/invoices/{id}/credit-notes — credit invoice lines proportionally.
 */
final class PostCreditNote
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
    public function execute(string $invoiceId, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'ar.credit.post');

        $companyId = $this->companyContext->companyId();
        $creditDate = (string) ($data['creditDate'] ?? $data['date'] ?? '');
        $reason = isset($data['reason']) ? trim((string) $data['reason']) : null;
        $rawLines = $data['lines'] ?? null;
        $requestId = $this->companyContext->requestId();

        if ($creditDate === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $creditDate)) {
            throw new BusinessRuleException('creditDate must be YYYY-MM-DD.');
        }

        $invoice = Invoice::query()
            ->where('company_id', $companyId)
            ->where('id', $invoiceId)
            ->where('status', 'posted')
            ->with('lines')
            ->first();
        if ($invoice === null) {
            throw new NotFoundException('Invoice not found.');
        }

        $open = (int) DB::selectOne('SELECT invoice_open(?::uuid, ?::date) AS open', [$invoiceId, $creditDate])->open;
        if ($open <= 0) {
            throw new BusinessRuleException('Invoice has no open balance to credit.');
        }

        return DB::transaction(function () use (
            $companyId,
            $invoice,
            $invoiceId,
            $creditDate,
            $reason,
            $rawLines,
            $open,
            $actorId,
            $requestId,
        ) {
            $creditId = Uuid::v7();
            $creditNo = $this->nextNumber($companyId, $creditDate, 'credit_note');

            // Full credit of remaining open, or explicit lines
            if ($rawLines === null || $rawLines === []) {
                // Proportional full remaining credit based on open / gross
                $ratio = $invoice->gross_minor > 0 ? ($open / $invoice->gross_minor) : 1.0;
                $net = (int) round($invoice->net_minor * $ratio);
                $vat = (int) round($invoice->vat_minor * $ratio);
                $nhil = (int) round($invoice->nhil_minor * $ratio);
                $getfund = (int) round($invoice->getfund_minor * $ratio);
                $covid = (int) round($invoice->covid_minor * $ratio);
                $gross = $net + $vat + $nhil + $getfund + $covid;
                // Adjust rounding to match open
                if ($gross !== $open) {
                    $net += ($open - $gross);
                    $gross = $open;
                }

                $lineModels = [];
                $lineNo = 1;
                foreach ($invoice->lines as $il) {
                    /** @var InvoiceLine $il */
                    $lineRatio = $invoice->gross_minor > 0 ? ($il->gross_minor / $invoice->gross_minor) : 0;
                    $lNet = (int) round($net * $lineRatio);
                    $lVat = (int) round($vat * $lineRatio);
                    $lNhil = (int) round($nhil * $lineRatio);
                    $lGet = (int) round($getfund * $lineRatio);
                    $lCovid = (int) round($covid * $lineRatio);
                    $lGross = $lNet + $lVat + $lNhil + $lGet + $lCovid;

                    $cnl = new CreditNoteLine;
                    $cnl->id = Uuid::v7();
                    $cnl->company_id = $companyId;
                    $cnl->credit_note_id = $creditId;
                    $cnl->invoice_line_id = $il->id;
                    $cnl->line_no = $lineNo++;
                    $cnl->description = $il->description;
                    $cnl->qty = $il->qty;
                    $cnl->net_minor = $lNet;
                    $cnl->vat_minor = $lVat;
                    $cnl->nhil_minor = $lNhil;
                    $cnl->getfund_minor = $lGet;
                    $cnl->covid_minor = $lCovid;
                    $cnl->gross_minor = $lGross;
                    $lineModels[] = $cnl;
                }
            } else {
                throw new BusinessRuleException('Partial line credits are not yet supported; omit lines for full remaining credit.');
            }

            $credit = new CreditNote;
            $credit->id = $creditId;
            $credit->company_id = $companyId;
            $credit->credit_no = $creditNo;
            $credit->invoice_id = $invoiceId;
            $credit->credit_date = $creditDate;
            $credit->currency = $invoice->currency;
            $credit->net_minor = $net;
            $credit->vat_minor = $vat;
            $credit->nhil_minor = $nhil;
            $credit->getfund_minor = $getfund;
            $credit->covid_minor = $covid;
            $credit->tax_total_minor = $vat + $nhil + $getfund + $covid;
            $credit->gross_minor = $gross;
            $credit->reason = $reason;
            $credit->created_by = $actorId;
            $credit->request_id = $requestId;
            $credit->save();

            foreach ($lineModels as $lm) {
                $lm->save();
            }

            // Reverse: Dr revenue + tax, Cr AR
            $lines = [
                new PostingLine(
                    accountId: $this->accountResolver->idByCode($companyId, 'revenue'),
                    debitMinor: $net,
                    creditMinor: 0,
                    partyId: $invoice->customer_id,
                    memo: "Credit {$creditNo}",
                ),
            ];
            if ($vat > 0) {
                $lines[] = new PostingLine(
                    accountId: $this->accountResolver->idByCode($companyId, 'vat'),
                    debitMinor: $vat,
                    creditMinor: 0,
                    memo: "Credit VAT {$creditNo}",
                );
            }
            if ($nhil > 0) {
                $lines[] = new PostingLine(
                    accountId: $this->accountResolver->idByCode($companyId, 'nhil'),
                    debitMinor: $nhil,
                    creditMinor: 0,
                    memo: "Credit NHIL {$creditNo}",
                );
            }
            if ($getfund > 0) {
                $lines[] = new PostingLine(
                    accountId: $this->accountResolver->idByCode($companyId, 'getfund'),
                    debitMinor: $getfund,
                    creditMinor: 0,
                    memo: "Credit GETFund {$creditNo}",
                );
            }
            if ($covid > 0) {
                $lines[] = new PostingLine(
                    accountId: $this->accountResolver->idByCode($companyId, 'covid'),
                    debitMinor: $covid,
                    creditMinor: 0,
                    memo: "Credit COVID {$creditNo}",
                );
            }
            $lines[] = new PostingLine(
                accountId: $this->accountResolver->idByCode($companyId, 'ar'),
                debitMinor: 0,
                creditMinor: $gross,
                partyId: $invoice->customer_id,
                memo: "Credit {$creditNo}",
            );

            $journalId = $this->postingService->post(new PostingRequest(
                companyId: $companyId,
                postingDate: $creditDate,
                postingKey: new PostingKey("credit_note:{$creditId}:post"),
                sourceType: 'credit_note',
                sourceId: $creditId,
                eventType: 'post',
                description: "Credit note {$creditNo} on {$invoice->invoice_no}",
                lines: $lines,
                actorId: $actorId,
                requestId: $requestId,
                currency: $invoice->currency,
            ));

            $credit->journal_id = $journalId;
            $credit->save();

            $this->auditWriter->write(
                action: 'credit_note.posted',
                entityType: 'credit_note',
                entityId: $creditId,
                detail: [
                    'creditNo' => $creditNo,
                    'invoiceId' => $invoiceId,
                    'grossMinor' => $gross,
                    'journalId' => $journalId,
                ],
                actorId: $actorId,
            );

            $this->outboxWriter->record([
                'aggregate_type' => 'credit_note',
                'aggregate_id' => $creditId,
                'event_type' => 'credit_note.posted',
                'payload' => ['creditNoteId' => $creditId, 'invoiceId' => $invoiceId],
            ]);

            return [
                'id' => $credit->id,
                'creditNo' => $credit->credit_no,
                'invoiceId' => $credit->invoice_id,
                'creditDate' => $credit->credit_date?->format('Y-m-d'),
                'netMinor' => $credit->net_minor,
                'tax' => [
                    'vat' => $credit->vat_minor,
                    'nhil' => $credit->nhil_minor,
                    'getfund' => $credit->getfund_minor,
                    'covid' => $credit->covid_minor,
                    'total' => $credit->tax_total_minor,
                ],
                'grossMinor' => $credit->gross_minor,
                'reason' => $credit->reason,
                'journalId' => $credit->journal_id,
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
            $seq->prefix = 'CN';
            $seq->save();
        }

        $value = $seq->next_value;
        $seq->next_value = $value + 1;
        $seq->save();

        return sprintf('CN-%s-%05d', $year, $value);
    }
}
