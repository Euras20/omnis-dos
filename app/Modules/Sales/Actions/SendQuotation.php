<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Company;
use App\Models\CompanyBankAccount;
use App\Models\DocumentFile;
use App\Models\Party;
use App\Models\Quotation;
use App\Modules\Sales\Domain\QuotationStatus;
use App\Modules\Sales\Support\QuotationDocument;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Outbox\OutboxWriter;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * POST /api/v1/quotations/{id}/send — email the quotation to the customer and mark it sent.
 */
final class SendQuotation
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
        $this->permissionChecker->assertCan($actorId, 'sales.quotes.manage');
        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($id, $companyId, $actorId, $data) {
            $quotation = Quotation::query()
                ->with('lines')
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($quotation === null) {
                throw new NotFoundException('Quotation not found.');
            }
            if ($quotation->status === QuotationStatus::CONVERTED) {
                throw new BusinessRuleException('Converted quotations cannot be sent.');
            }

            $customer = Party::query()
                ->where('company_id', $companyId)
                ->where('id', $quotation->customer_id)
                ->first();

            $to = isset($data['to']) && is_string($data['to']) && $data['to'] !== ''
                ? $data['to']
                : (string) ($customer->email ?? '');
            if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
                throw new BusinessRuleException('Customer has no email. Pass to, or set the customer email.');
            }

            $banks = CompanyBankAccount::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('bank_name')
                ->get();

            $company = Company::query()->where('id', $companyId)->firstOrFail();
            $downloadUrl = rtrim((string) config('app.url'), '/')
                .'/api/v1/public/quotations/'.$quotation->id.'/pdf?token='.QuotationDocument::token($quotation->id);
            $doc = QuotationDocument::build($quotation, $customer, $company, $banks, $downloadUrl);

            $document = $this->storePdf($doc['pdf'], $quotation, $companyId, $actorId);

            $this->outboxWriter->record([
                'aggregate_type' => 'quotation',
                'aggregate_id' => $quotation->id,
                'event_type' => 'email.send',
                'payload' => [
                    'companyId' => $companyId,
                    'to' => $to,
                    'subject' => 'Quotation '.$quotation->quotation_no,
                    'body' => 'Please find quotation '.$quotation->quotation_no.' attached.',
                    'html' => $doc['html'],
                    'pdfBase64' => base64_encode($doc['pdf']),
                    'pdfName' => $quotation->quotation_no.'.pdf',
                    'entityType' => 'quotation',
                    'entityId' => $quotation->id,
                    'documentFileId' => $document->id,
                    'idempotencyKey' => 'quotation-send-'.$quotation->id.'-'.now()->timestamp,
                ],
            ]);

            $from = $quotation->status;
            if ($quotation->status === QuotationStatus::DRAFT) {
                $quotation->status = QuotationStatus::SENT;
                $quotation->save();
            }

            $this->auditWriter->write(
                action: 'quotation.sent',
                entityType: 'quotation',
                entityId: $quotation->id,
                detail: [
                    'quotationNo' => $quotation->quotation_no,
                    'to' => $to,
                    'from' => $from,
                    'status' => $quotation->status,
                ],
                actorId: $actorId,
            );

            return [
                'id' => $quotation->id,
                'quotationNo' => $quotation->quotation_no,
                'status' => $quotation->status,
                'sentTo' => $to,
                'documentFileId' => $document->id,
            ];
        });
    }

    private function storePdf(string $bytes, Quotation $quotation, string $companyId, ?string $actorId): DocumentFile
    {
        $key = sprintf('companies/%s/documents/quotation/%s/%s.pdf', $companyId, $quotation->id, Uuid::v7());
        Storage::disk('local')->put($key, $bytes);

        return DocumentFile::query()->create([
            'id' => Uuid::v7(),
            'company_id' => $companyId,
            'original_name' => $quotation->quotation_no.'.pdf',
            'storage_key' => $key,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($bytes),
            'scan_status' => 'clean',
            'entity_type' => 'quotation',
            'entity_id' => $quotation->id,
            'uploaded_by' => $actorId,
            'uploaded_at' => now(),
        ]);
    }
}
