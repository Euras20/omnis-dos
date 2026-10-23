<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Queries;

use App\Exceptions\NotFoundException;
use App\Models\CompanyBankAccount;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\Quotation;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * Print payloads for quotation and invoice templates.
 * Includes every active company bank account.
 */
final class DocumentPrintQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function quotation(string $id): array
    {
        $this->permissionChecker->assertCan($this->companyContext->userId(), 'sales.quotes.view');
        $companyId = $this->companyContext->companyId();

        $quotation = Quotation::query()
            ->with('lines')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();
        if ($quotation === null) {
            throw new NotFoundException('Quotation not found.');
        }

        $customer = Party::query()->where('id', $quotation->customer_id)->first();

        return [
            'type' => 'quotation',
            'number' => $quotation->quotation_no,
            'date' => $quotation->quotation_date?->format('Y-m-d'),
            'validUntil' => $quotation->valid_until?->format('Y-m-d'),
            'currency' => $quotation->currency,
            'status' => $quotation->status,
            'deliveryLocation' => $quotation->delivery_location,
            'deliveryType' => $quotation->delivery_type,
            'supplierQuoteRef' => $quotation->supplier_quote_ref,
            'memo' => $quotation->memo,
            'netMinor' => $quotation->net_minor,
            'taxTotalMinor' => $quotation->tax_total_minor,
            'grossMinor' => $quotation->gross_minor,
            'customer' => $this->party($customer),
            'lines' => $quotation->lines->map(static fn ($l) => [
                'lineNo' => $l->line_no,
                'itemId' => $l->item_id,
                'toOrder' => (bool) $l->to_order,
                'description' => $l->description,
                'qty' => (string) $l->qty,
                'unit' => $l->unit,
                'unitPriceMinor' => $l->unit_price_minor,
                'netMinor' => $l->net_minor,
            ])->all(),
            'bankAccounts' => $this->banks($companyId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function invoice(string $id): array
    {
        $actor = $this->companyContext->userId();
        if (! $this->permissionChecker->can($actor, 'ar.invoices.view')) {
            $this->permissionChecker->assertCan($actor, 'finance.view');
        }
        $companyId = $this->companyContext->companyId();

        $invoice = Invoice::query()
            ->with('lines')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();
        if ($invoice === null) {
            throw new NotFoundException('Invoice not found.');
        }

        $customer = Party::query()->where('id', $invoice->customer_id)->first();

        return [
            'type' => 'invoice',
            'number' => $invoice->invoice_no,
            'date' => $invoice->invoice_date?->format('Y-m-d'),
            'dueDate' => $invoice->due_date?->format('Y-m-d'),
            'currency' => $invoice->currency,
            'status' => $invoice->status,
            'netMinor' => $invoice->net_minor,
            'taxTotalMinor' => $invoice->tax_total_minor,
            'grossMinor' => $invoice->gross_minor,
            'customer' => $this->party($customer),
            'lines' => $invoice->lines->map(static fn ($l) => [
                'lineNo' => $l->line_no,
                'description' => $l->description,
                'qty' => (string) $l->qty,
                'unit' => $l->unit,
                'unitPriceMinor' => $l->unit_price_minor,
                'netMinor' => $l->net_minor,
            ])->all(),
            'bankAccounts' => $this->banks($companyId),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function party(?Party $party): ?array
    {
        if ($party === null) {
            return null;
        }

        return [
            'id' => $party->id,
            'code' => $party->code,
            'name' => $party->name,
            'email' => $party->email,
            'address' => $party->address,
            'deliveryAddress' => $party->delivery_address,
            'deliveryTerms' => $party->delivery_terms,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function banks(string $companyId): array
    {
        return CompanyBankAccount::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('bank_name')
            ->get()
            ->map(static fn (CompanyBankAccount $a) => [
                'bankName' => $a->bank_name,
                'accountName' => $a->account_name,
                'accountNumber' => $a->account_number,
                'branch' => $a->branch,
                'swift' => $a->swift,
                'currency' => $a->currency,
            ])
            ->all();
    }
}
