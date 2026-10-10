<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\StockAssignment;
use App\Modules\Inventory\Domain\AssignmentStatus;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/stock/assignments/{id}/decide — stock keeper confirms or rejects.
 * SoD: decided_by ≠ requested_by (enforced by DB trigger + application).
 */
final class DecideAssignment
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data  decision: "confirm" | "reject", note?: string
     * @return array<string, mixed>
     */
    public function execute(string $assignmentId, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'stock.approve');

        $companyId = $this->companyContext->companyId();
        $decision = strtolower(trim((string) ($data['decision'] ?? '')));
        $note = isset($data['note']) ? trim((string) $data['note']) : null;

        if (! in_array($decision, ['confirm', 'reject'], true)) {
            throw new BusinessRuleException('decision must be "confirm" or "reject".');
        }

        return DB::transaction(function () use (
            $companyId,
            $assignmentId,
            $decision,
            $note,
            $actorId,
        ) {
            $assignment = StockAssignment::query()
                ->where('company_id', $companyId)
                ->where('id', $assignmentId)
                ->lockForUpdate()
                ->first();

            if ($assignment === null) {
                throw new NotFoundException('Stock assignment not found.');
            }

            if ($assignment->status !== AssignmentStatus::PENDING) {
                throw new BusinessRuleException(
                    "Only pending assignments can be decided (current status: {$assignment->status}).",
                );
            }

            if ($assignment->requested_by !== null && $assignment->requested_by === $actorId) {
                throw new BusinessRuleException(
                    'Separation of duties: the requester cannot confirm or reject their own assignment.',
                );
            }

            $newStatus = $decision === 'confirm'
                ? AssignmentStatus::ASSIGNED
                : AssignmentStatus::REJECTED;

            $assignment->status = $newStatus;
            $assignment->decided_by = $actorId;
            $assignment->decided_at = now();
            $assignment->decision_note = $note;
            $assignment->save();

            $this->auditWriter->write(
                action: 'stock.assignment.decide',
                entityType: 'stock_assignment',
                entityId: $assignment->id,
                detail: [
                    'decision' => $decision,
                    'status' => $newStatus,
                    'note' => $note,
                    'requestedBy' => $assignment->requested_by,
                    'decidedBy' => $actorId,
                ],
            );

            return [
                'id' => $assignment->id,
                'itemId' => $assignment->item_id,
                'locationId' => $assignment->location_id,
                'lot' => $assignment->lot,
                'qty' => (string) $assignment->qty,
                'status' => $assignment->status,
                'salesOrderLineId' => $assignment->sales_order_line_id,
                'requestedBy' => $assignment->requested_by,
                'decidedBy' => $assignment->decided_by,
                'decidedAt' => $assignment->decided_at?->toIso8601String(),
                'decisionNote' => $assignment->decision_note,
                'memo' => $assignment->memo,
            ];
        });
    }
}
