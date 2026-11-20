<?php

declare(strict_types=1);

namespace App\Modules\Receivables\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Invoice;
use App\Models\Party;
use App\Modules\Ledger\Domain\PostingKey;
use App\Modules\Ledger\Domain\PostingLine;
use App\Modules\Ledger\Domain\PostingRequest;
use App\Modules\Ledger\Services\PostingService;
use App\Modules\Ledger\Services\RoleResolver;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/parties/{id}/opening-balance — customer opening balance invoice.
 *
 * Creates an open invoice (Dr AR / Cr 3900) that receipts can be applied to.
 */
final class PostCustomerOpeningBalance
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly PostingService $postingService,
        private readonly RoleResolver $roleResolver,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function execute(string $partyId, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'customers.manage');

        $amount = (int) ($data['amountMinor'] ?? 0);
        if ($amount <= 0) {
            throw new BusinessRuleException('amountMinor must be greater than 0.');
        }

        $asOf = (string) ($data['asOfDate'] ?? '');
        if ($asOf === '') {
            throw new BusinessRuleException('asOfDate is required.');
        }

        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($partyId, $data, $amount, $asOf, $companyId, $actorId) {
            $party = Party::query()
                ->where('company_id', $companyId)
                ->where('id', $partyId)
                ->where('party_type', 'customer')
                ->lockForUpdate()
                ->first();

            if ($party === null) {
                throw new NotFoundException('Customer not found.');
            }

            if ($party->opening_balance_doc_id !== null) {
                throw new BusinessRuleException('Opening balance already recorded. Correct via journal.');
            }

            $arId = $party->ar_account_id ?? $this->roleResolver->id($companyId, 'CUSTOMER_AR');
            $clearingId = $this->clearingId($companyId);

            $invoiceId = Uuid::v7();
            $invoiceNo = 'OB-'.$party->code;

            $invoice = new Invoice;
            $invoice->id = $invoiceId;
            $invoice->company_id = $companyId;
            $invoice->invoice_no = $invoiceNo;
            $invoice->customer_id = $party->id;
            $invoice->invoice_date = $asOf;
            $invoice->due_date = $data['dueDate'] ?? $asOf;
            $invoice->currency = $party->currency ?? 'GHS';
            $invoice->net_minor = $amount;
            $invoice->tax_total_minor = 0;
            $invoice->gross_minor = $amount;
            $invoice->status = 'posted';
            $invoice->memo = 'Opening balance'.(isset($data['reference']) ? ' · '.$data['reference'] : '');
            $invoice->created_by = $actorId;
            $invoice->save();

            $journalId = $this->postingService->post(new PostingRequest(
                companyId: $companyId,
                postingDate: $asOf,
                postingKey: PostingKey::make('opening_balance', $invoiceId, 'customer'),
                sourceType: 'opening_balance',
                sourceId: $invoiceId,
                eventType: 'customer_opening',
                description: "Opening balance {$party->name}",
                lines: [
                    new PostingLine(accountId: $arId, debitMinor: $amount, creditMinor: 0, partyId: $party->id),
                    new PostingLine(accountId: $clearingId, debitMinor: 0, creditMinor: $amount),
                ],
                actorId: $actorId,
                currency: $invoice->currency,
            ));

            $invoice->journal_id = $journalId;
            $invoice->save();

            $party->opening_balance_doc_id = $invoiceId;
            $party->opening_balance_date = $asOf;
            $party->opening_balance_minor = $amount;
            $party->save();

            $this->auditWriter->write(
                action: 'party.opening_balance',
                entityType: 'party',
                entityId: $party->id,
                detail: ['invoiceId' => $invoiceId, 'amountMinor' => $amount, 'asOf' => $asOf],
                actorId: $actorId,
            );

            return [
                'partyId' => $party->id,
                'invoiceId' => $invoiceId,
                'invoiceNo' => $invoiceNo,
                'amountMinor' => $amount,
                'asOfDate' => $asOf,
                'journalId' => $journalId,
            ];
        });
    }

    private function clearingId(string $companyId): string
    {
        return $this->roleResolver->id($companyId, 'OPENING_BALANCE_EQUITY');
    }
}
