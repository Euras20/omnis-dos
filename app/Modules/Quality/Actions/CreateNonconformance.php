<?php

declare(strict_types=1);

namespace App\Modules\Quality\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\Item;
use App\Models\Nonconformance;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * Create a nonconformance (NCR).
 *
 * Used by:
 * - Explicit POST /api/v1/quality/nonconformances
 * - QC reject form (why / when / action)
 * - Auto-create from RecordQcResult when quarantine or reject qty > 0
 */
final class CreateNonconformance
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
    public function execute(array $data, bool $skipPermissionCheck = false): array
    {
        if (! $skipPermissionCheck) {
            $actorId = $this->companyContext->userId();
            $this->permissionChecker->assertCan($actorId, 'quality.manage');
        }

        $companyId = $this->companyContext->companyId();
        $actorId = $this->companyContext->userId();

        $issue = trim((string) ($data['issue'] ?? $data['why'] ?? ''));
        if ($issue === '') {
            throw new BusinessRuleException('Issue (why) is required.');
        }

        $sourceType = (string) ($data['sourceType'] ?? 'manual');
        $sourceId = isset($data['sourceId']) ? (string) $data['sourceId'] : null;
        $itemId = isset($data['itemId']) ? (string) $data['itemId'] : null;
        $sku = isset($data['sku']) ? trim((string) $data['sku']) : null;
        $qty = (string) ($data['qty'] ?? '0');
        $cause = isset($data['cause']) ? trim((string) $data['cause']) : null;
        $action = isset($data['action']) ? trim((string) $data['action']) : null;
        $dueDate = isset($data['dueDate']) ? (string) $data['dueDate'] : null;
        $occurredAt = isset($data['occurredAt']) ? (string) $data['occurredAt'] : (isset($data['when']) ? (string) $data['when'] : null);
        $ownerId = isset($data['ownerId']) ? (string) $data['ownerId'] : $actorId;

        if ($itemId === null && $sku !== null && $sku !== '') {
            $item = Item::query()
                ->where('company_id', $companyId)
                ->where('sku', $sku)
                ->first();
            $itemId = $item?->id;
        }

        return DB::transaction(function () use (
            $companyId,
            $actorId,
            $issue,
            $sourceType,
            $sourceId,
            $itemId,
            $sku,
            $qty,
            $cause,
            $action,
            $dueDate,
            $occurredAt,
            $ownerId,
        ) {
            // Idempotent auto-create from the same QC line: return existing open NCR.
            if ($sourceType === 'qc_line' && $sourceId !== null) {
                $existing = Nonconformance::query()
                    ->where('company_id', $companyId)
                    ->where('source_type', 'qc_line')
                    ->where('source_id', $sourceId)
                    ->where('status', 'open')
                    ->first();
                if ($existing !== null) {
                    return $this->toArray($existing);
                }
            }

            $ncrNo = $this->nextNcrNo($companyId);
            $id = Uuid::v7();

            $ncr = new Nonconformance([
                'id' => $id,
                'company_id' => $companyId,
                'ncr_no' => $ncrNo,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'item_id' => $itemId,
                'sku' => $sku,
                'qty' => $qty,
                'issue' => $issue,
                'cause' => $cause,
                'action' => $action,
                'owner_id' => $ownerId,
                'status' => 'open',
                'due_date' => $dueDate,
                'occurred_at' => $occurredAt,
            ]);
            $ncr->save();

            $this->auditWriter->write(
                action: 'nonconformance.created',
                entityType: 'nonconformance',
                entityId: $id,
                detail: [
                    'ncr_no' => $ncrNo,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'item_id' => $itemId,
                    'sku' => $sku,
                    'qty' => $qty,
                ],
                actorId: $actorId,
            );

            return $this->toArray($ncr);
        });
    }

    private function nextNcrNo(string $companyId): string
    {
        $year = date('Y');
        $prefix = "NCR-{$year}-";
        $last = Nonconformance::query()
            ->where('company_id', $companyId)
            ->where('ncr_no', 'like', $prefix.'%')
            ->orderByDesc('ncr_no')
            ->value('ncr_no');

        $seq = 1;
        if (is_string($last) && str_starts_with($last, $prefix)) {
            $seq = (int) substr($last, strlen($prefix)) + 1;
        }

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Nonconformance $ncr): array
    {
        return [
            'id' => $ncr->id,
            'ncrNo' => $ncr->ncr_no,
            'sourceType' => $ncr->source_type,
            'sourceId' => $ncr->source_id,
            'itemId' => $ncr->item_id,
            'sku' => $ncr->sku,
            'qty' => (string) $ncr->qty,
            'issue' => $ncr->issue,
            'cause' => $ncr->cause,
            'action' => $ncr->action,
            'ownerId' => $ncr->owner_id,
            'status' => $ncr->status,
            'dueDate' => $ncr->due_date?->format('Y-m-d'),
            'occurredAt' => $ncr->occurred_at?->format('Y-m-d'),
            'correctiveActionId' => $ncr->corrective_action_id,
            'createdAt' => $ncr->created_at?->toIso8601String(),
        ];
    }
}
