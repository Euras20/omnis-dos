<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Quotation;
use App\Modules\Sales\Domain\QuotationStatus;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/quotations/{id}/status — mark Sent, Accepted, Declined or Expired.
 */
final class ChangeQuotationStatus
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function execute(string $id, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'sales.quote');

        $companyId = $this->companyContext->companyId();
        $newStatus = strtolower((string) ($data['status'] ?? ''));

        if (! QuotationStatus::isTransitionTarget($newStatus)) {
            throw new BusinessRuleException(
                'status must be one of: sent, accepted, declined, expired.'
            );
        }

        return DB::transaction(function () use ($id, $companyId, $newStatus, $actorId) {
            $quotation = Quotation::query()
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($quotation === null) {
                throw new NotFoundException('Quotation not found.');
            }

            if ($quotation->status === QuotationStatus::CONVERTED) {
                throw new BusinessRuleException('Converted quotations cannot change status.');
            }

            if ($quotation->status === $newStatus) {
                return $this->summary($quotation);
            }

            $from = $quotation->status;
            $quotation->status = $newStatus;
            $quotation->save();

            $this->auditWriter->write(
                action: 'quotation.status_changed',
                entityType: 'quotation',
                entityId: $quotation->id,
                detail: [
                    'from' => $from,
                    'to' => $newStatus,
                    'quotationNo' => $quotation->quotation_no,
                ],
                actorId: $actorId,
            );

            return $this->summary($quotation);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Quotation $quotation): array
    {
        return [
            'id' => $quotation->id,
            'quotationNo' => $quotation->quotation_no,
            'status' => $quotation->status,
            'customerId' => $quotation->customer_id,
            'grossMinor' => $quotation->gross_minor,
        ];
    }
}
