<?php

declare(strict_types=1);

namespace App\Modules\Quality\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\CorrectiveAction;
use App\Models\Nonconformance;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * Send an open nonconformance to a Corrective Action (CAPA) with comments.
 *
 * POST /api/v1/quality/nonconformances/{id}/send-to-corrective-action
 */
final class SendToCorrectiveAction
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
    public function execute(string $nonconformanceId, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'quality.manage');

        $companyId = $this->companyContext->companyId();

        $comments = trim((string) ($data['comments'] ?? $data['action'] ?? ''));
        if ($comments === '') {
            throw new BusinessRuleException('Comments (or action) are required when sending to a corrective action.');
        }

        $actionText = trim((string) ($data['action'] ?? $comments));
        $dueDate = isset($data['dueDate']) ? (string) $data['dueDate'] : null;
        $ownerId = isset($data['ownerId']) ? (string) $data['ownerId'] : $actorId;
        $verifierId = isset($data['verifierId']) ? (string) $data['verifierId'] : null;

        return DB::transaction(function () use (
            $nonconformanceId,
            $companyId,
            $actorId,
            $comments,
            $actionText,
            $dueDate,
            $ownerId,
            $verifierId,
        ) {
            $ncr = Nonconformance::query()
                ->where('company_id', $companyId)
                ->where('id', $nonconformanceId)
                ->lockForUpdate()
                ->first();

            if ($ncr === null) {
                throw new NotFoundException('Nonconformance not found.');
            }

            if ($ncr->status !== 'open') {
                throw new BusinessRuleException('Only an open nonconformance can be sent to a corrective action.');
            }

            if ($ncr->corrective_action_id !== null) {
                throw new BusinessRuleException('This nonconformance already has a corrective action.');
            }

            $capaNo = $this->nextCapaNo($companyId);
            $capaId = Uuid::v7();

            $capa = new CorrectiveAction([
                'id' => $capaId,
                'company_id' => $companyId,
                'capa_no' => $capaNo,
                'nonconformance_id' => $ncr->id,
                'action' => $actionText,
                'comments' => $comments,
                'owner_id' => $ownerId,
                'verifier_id' => $verifierId,
                'status' => 'open',
                'due_date' => $dueDate,
            ]);
            $capa->save();

            $ncr->corrective_action_id = $capaId;
            $ncr->action = $ncr->action ?: $actionText;
            $ncr->save();

            $this->auditWriter->write(
                action: 'nonconformance.sent_to_corrective_action',
                entityType: 'corrective_action',
                entityId: $capaId,
                detail: [
                    'capa_no' => $capaNo,
                    'nonconformance_id' => $ncr->id,
                    'ncr_no' => $ncr->ncr_no,
                ],
                actorId: $actorId,
            );

            return [
                'id' => $capa->id,
                'capaNo' => $capa->capa_no,
                'nonconformanceId' => $capa->nonconformance_id,
                'ncrNo' => $ncr->ncr_no,
                'action' => $capa->action,
                'comments' => $capa->comments,
                'ownerId' => $capa->owner_id,
                'verifierId' => $capa->verifier_id,
                'status' => $capa->status,
                'dueDate' => $capa->due_date?->format('Y-m-d'),
                'createdAt' => $capa->created_at?->toIso8601String(),
            ];
        });
    }

    private function nextCapaNo(string $companyId): string
    {
        $year = date('Y');
        $prefix = "CAPA-{$year}-";
        $last = CorrectiveAction::query()
            ->where('company_id', $companyId)
            ->where('capa_no', 'like', $prefix.'%')
            ->orderByDesc('capa_no')
            ->value('capa_no');

        $seq = 1;
        if (is_string($last) && str_starts_with($last, $prefix)) {
            $seq = (int) substr($last, strlen($prefix)) + 1;
        }

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
