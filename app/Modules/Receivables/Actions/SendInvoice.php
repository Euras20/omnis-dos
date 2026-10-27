<?php

declare(strict_types=1);

namespace App\Modules\Receivables\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Company;
use App\Models\CompanyBankAccount;
use App\Models\Invoice;
use App\Models\Party;
use App\Modules\Receivables\Support\InvoiceDocument;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Outbox\OutboxWriter;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/invoices/{id}/send — email the invoice to the customer.
 */
final class SendInvoice
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
        private readonly OutboxWriter $outboxWriter,
    ) {
    }

    /**
     * @param  array{to?: ?string}  $data
     * @return array<string, mixed>
     */
    public function execute(string $id, array $data = []): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'ar.invoice.post');
        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($id, $companyId, $actorId, $data) {
            $invoice = Invoice::query()
                ->with(['lines.item'])
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->first();
            if ($invoice === null) {
                throw new NotFoundException('Invoice not found.');
            }
            if ($invoice->status === 'void') {
                throw new BusinessRuleException('A void invoice cannot be sent.');
            }

            $customer = Party::query()->where('company_id', $companyId)->where('id', $invoice->customer_id)->first();
            $to = isset($data['to']) && is_string($data['to']) && $data['to'] !== ''
                ? $data['to']
                : (string) ($customer->email ?? '');
            if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
                throw new BusinessRuleException('Customer has no email. Pass to, or set the customer email.');
            }

            $company = Company::query()->where('id', $companyId)->firstOrFail();
            $banks = CompanyBankAccount::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('bank_name')
                ->get();
            $doc = InvoiceDocument::build($invoice, $customer, $company, $banks);

            $this->outboxWriter->record([
                'aggregate_type' => 'invoice',
                'aggregate_id' => $invoice->id,
                'event_type' => 'email.send',
                'payload' => [
                    'companyId' => $companyId,
                    'to' => $to,
                    'subject' => 'Invoice '.$invoice->invoice_no,
                    'body' => 'Please find invoice '.$invoice->invoice_no.' attached.',
                    'html' => $doc['html'],
                    'pdfBase64' => base64_encode($doc['pdf']),
                    'pdfName' => $invoice->invoice_no.'.pdf',
                    'entityType' => 'invoice',
                    'entityId' => $invoice->id,
                    'idempotencyKey' => 'invoice-send-'.$invoice->id.'-'.now()->timestamp,
                ],
            ]);

            $this->auditWriter->write(
                action: 'invoice.sent',
                entityType: 'invoice',
                entityId: $invoice->id,
                detail: ['invoiceNo' => $invoice->invoice_no, 'to' => $to],
                actorId: $actorId,
            );

            return [
                'id' => $invoice->id,
                'invoiceNo' => $invoice->invoice_no,
                'sentTo' => $to,
            ];
        });
    }
}
