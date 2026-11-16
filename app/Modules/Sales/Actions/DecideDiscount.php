<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/quotations/{id}/discount/approve|reject
 * POST /api/v1/sales-orders/{id}/discount/approve|reject
 *
 * Approves or rejects a pending discount.
 */
final class DecideDiscount
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
    public function execute(string $entityType, string $id, string $decision, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'sales.discount.approve');

        if (! in_array($decision, ['approve', 'reject'], true)) {
            throw new BusinessRuleException('decision must be approve or reject.');
        }

        $reason = trim((string) ($data['reason'] ?? ''));
        if ($decision === 'reject' && strlen($reason) < 5) {
            throw new BusinessRuleException('A reason of at least 5 characters is required to reject.');
        }

        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($entityType, $id, $decision, $reason, $companyId, $actorId) {
            $model = $entityType === 'quotation'
                ? Quotation::query()
                : SalesOrder::query();

            $row = $model
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new NotFoundException(ucfirst($entityType).' not found.');
            }

            if ($row->discount_status !== 'pending') {
                throw new BusinessRuleException('Discount is not pending approval.');
            }

            if ($decision === 'approve') {
                $row->discount_status = 'approved';
                $row->discount_approved_by = $actorId;
                $row->discount_approved_at = now();
                $row->discount_reason = $reason !== '' ? $reason : null;
            } else {
                $row->discount_status = 'rejected';
                $row->discount_reason = $reason;
            }

            $row->save();

            $this->auditWriter->write(
                action: "discount.{$decision}",
                entityType: $entityType,
                entityId: $row->id,
                detail: ['reason' => $reason, 'discountPercent' => $row->discount_percent],
                actorId: $actorId,
            );

            return [
                'id' => $row->id,
                'discountStatus' => $row->discount_status,
                'discountApprovedBy' => $row->discount_approved_by,
                'discountApprovedAt' => $row->discount_approved_at?->toIso8601String(),
                'discountReason' => $row->discount_reason,
            ];
        });
    }
}
