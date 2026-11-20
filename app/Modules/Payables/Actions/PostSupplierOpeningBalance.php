<?php

declare(strict_types=1);

namespace App\Modules\Payables\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Bill;
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
 * POST /api/v1/parties/{id}/opening-balance — supplier opening balance bill.
 *
 * Creates an open bill (Dr 3900 / Cr AP) that payments can be made against.
 */
final class PostSupplierOpeningBalance
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
        $this->permissionChecker->assertCan($actorId, 'suppliers.manage');

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
                ->whereIn('party_type', ['supplier', 'carrier', 'broker'])
                ->lockForUpdate()
                ->first();

            if ($party === null) {
                throw new NotFoundException('Supplier not found.');
            }

            if ($party->opening_balance_doc_id !== null) {
                throw new BusinessRuleException('Opening balance already recorded. Correct via journal.');
            }

            $apId = $party->ap_account_id ?? $this->roleResolver->id($companyId, 'SUPPLIER_AP');
            $clearingId = $this->clearingId($companyId);

            $billId = Uuid::v7();
            $billNo = 'OB-'.$party->code;

            $bill = new Bill;
            $bill->id = $billId;
            $bill->company_id = $companyId;
            $bill->bill_no = $billNo;
            $bill->supplier_id = $party->id;
            $bill->supplier_ref = $data['reference'] ?? 'Opening balance';
            $bill->bill_date = $asOf;
            $bill->due_date = $data['dueDate'] ?? $asOf;
            $bill->currency = $party->currency ?? 'GHS';
            $bill->net_minor = $amount;
            $bill->tax_minor = 0;
            $bill->gross_minor = $amount;
            $bill->status = 'posted';
            $bill->memo = 'Opening balance';
            $bill->created_by = $actorId;
            $bill->save();

            $journalId = $this->postingService->post(new PostingRequest(
                companyId: $companyId,
                postingDate: $asOf,
                postingKey: PostingKey::make('opening_balance', $billId, 'supplier'),
                sourceType: 'opening_balance',
                sourceId: $billId,
                eventType: 'supplier_opening',
                description: "Opening balance {$party->name}",
                lines: [
                    new PostingLine(accountId: $clearingId, debitMinor: $amount, creditMinor: 0),
                    new PostingLine(accountId: $apId, debitMinor: 0, creditMinor: $amount, partyId: $party->id),
                ],
                actorId: $actorId,
                currency: $bill->currency,
            ));

            $bill->journal_id = $journalId;
            $bill->save();

            $party->opening_balance_doc_id = $billId;
            $party->opening_balance_date = $asOf;
            $party->opening_balance_minor = $amount;
            $party->save();

            $this->auditWriter->write(
                action: 'party.opening_balance',
                entityType: 'party',
                entityId: $party->id,
                detail: ['billId' => $billId, 'amountMinor' => $amount, 'asOf' => $asOf],
                actorId: $actorId,
            );

            return [
                'partyId' => $party->id,
                'billId' => $billId,
                'billNo' => $billNo,
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
