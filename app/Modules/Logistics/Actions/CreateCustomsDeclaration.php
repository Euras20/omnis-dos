<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Actions;

use App\Exceptions\NotFoundException;
use App\Models\CustomsDeclaration;
use App\Models\NumberSequence;
use App\Models\Shipment;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Outbox\OutboxWriter;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/customs — create a customs declaration linked to a shipment
 * (and optionally a specific consignment).
 */
final class CreateCustomsDeclaration
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
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
        $this->permissionChecker->assertCan($actorId, 'logistics.customs');

        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($data, $companyId, $actorId) {
            $shipmentId = (string) ($data['shipmentId'] ?? '');
            $shipment = Shipment::query()
                ->where('company_id', $companyId)
                ->where('id', $shipmentId)
                ->first();

            if ($shipment === null) {
                throw new NotFoundException('Shipment not found.');
            }

            $year = date('Y');
            $seq = NumberSequence::query()
                ->where('company_id', $companyId)
                ->where('doc_type', 'customs_declaration')
                ->where('fiscal_year', $year)
                ->lockForUpdate()
                ->first();

            if ($seq === null) {
                $seq = new NumberSequence;
                $seq->id = Uuid::v7();
                $seq->company_id = $companyId;
                $seq->doc_type = 'customs_declaration';
                $seq->fiscal_year = $year;
                $seq->next_value = 1;
                $seq->prefix = 'CD';
                $seq->save();
            }

            $value = $seq->next_value;
            $seq->next_value = $value + 1;
            $seq->save();

            $declNo = sprintf('CD-%s-%05d', $year, $value);

            $decl = new CustomsDeclaration;
            $decl->id = Uuid::v7();
            $decl->company_id = $companyId;
            $decl->declaration_no = $declNo;
            $decl->shipment_id = $shipmentId;
            $decl->consignment_id = $data['consignmentId'] ?? null;
            $decl->jurisdiction = $data['jurisdiction'] ?? null;
            $decl->broker_id = $data['brokerId'] ?? null;
            $decl->hs_code = $data['hsCode'] ?? null;
            $decl->valuation = $data['valuation'] ?? null;
            $decl->duties_minor = (int) ($data['dutiesMinor'] ?? 0);
            $decl->recoverable_tax_minor = (int) ($data['recoverableTaxMinor'] ?? 0);
            $decl->nonrecoverable_minor = (int) ($data['nonrecoverableMinor'] ?? 0);
            $decl->inspection = $data['inspection'] ?? null;
            $decl->status = 'Pre-lodged';
            $decl->free_time_end = $data['freeTimeEnd'] ?? null;
            $decl->demurrage_minor = (int) ($data['demurrageMinor'] ?? 0);
            $decl->docs = $data['docs'] ?? [];
            $decl->row_version = 1;
            $decl->created_by = $actorId;
            $decl->save();

            $this->auditWriter->write(
                'customs.created',
                'customs_declaration',
                $decl->id,
                ['declarationNo' => $declNo, 'shipmentId' => $shipmentId],
            );

            $this->outboxWriter->record([
                'aggregate_type' => 'customs_declaration',
                'aggregate_id' => $decl->id,
                'event_type' => 'customs.created',
                'payload' => ['declarationNo' => $declNo],
            ]);

            return $this->toArray($decl);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(CustomsDeclaration $d): array
    {
        return [
            'id' => $d->id,
            'declarationNo' => $d->declaration_no,
            'shipmentId' => $d->shipment_id,
            'consignmentId' => $d->consignment_id,
            'jurisdiction' => $d->jurisdiction,
            'hsCode' => $d->hs_code,
            'dutiesMinor' => $d->duties_minor,
            'recoverableTaxMinor' => $d->recoverable_tax_minor,
            'nonrecoverableMinor' => $d->nonrecoverable_minor,
            'status' => $d->status,
            'freeTimeEnd' => $d->free_time_end?->format('Y-m-d'),
            'demurrageMinor' => $d->demurrage_minor,
            'docs' => $d->docs,
            'createdAt' => $d->created_at?->toIso8601String(),
        ];
    }
}
