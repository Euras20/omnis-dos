<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Queries;

use App\Exceptions\NotFoundException;
use App\Models\CustomsDeclaration;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/** GET /api/v1/customs/{id} */
final class GetCustomsQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $id): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'logistics.view');

        $companyId = $this->companyContext->companyId();

        $d = CustomsDeclaration::query()
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if ($d === null) {
            throw new NotFoundException('Customs declaration not found.');
        }

        return [
            'id' => $d->id,
            'declarationNo' => $d->declaration_no,
            'shipmentId' => $d->shipment_id,
            'consignmentId' => $d->consignment_id,
            'jurisdiction' => $d->jurisdiction,
            'brokerId' => $d->broker_id,
            'hsCode' => $d->hs_code,
            'valuation' => $d->valuation,
            'dutiesMinor' => $d->duties_minor,
            'recoverableTaxMinor' => $d->recoverable_tax_minor,
            'nonrecoverableMinor' => $d->nonrecoverable_minor,
            'inspection' => $d->inspection,
            'status' => $d->status,
            'payment' => $d->payment,
            'releaseDate' => $d->release_date?->format('Y-m-d'),
            'freeTimeEnd' => $d->free_time_end?->format('Y-m-d'),
            'demurrageMinor' => $d->demurrage_minor,
            'docs' => $d->docs,
            'rowVersion' => $d->row_version,
            'createdAt' => $d->created_at?->toIso8601String(),
        ];
    }
}
