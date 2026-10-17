<?php

declare(strict_types=1);

namespace App\Modules\Quality\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Nonconformance;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * Close an open nonconformance (e.g. after quarantine is certified to stock).
 *
 * POST /api/v1/quality/nonconformances/{id}/close
 */
final class CloseNonconformance
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
    public function execute(string $nonconformanceId, array $data = []): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'quality.manage');

        $companyId = $this->companyContext->companyId();
        $note = isset($data['note']) ? trim((string) $data['note']) : null;

        return DB::transaction(function () use ($nonconformanceId, $companyId, $actorId, $note) {
            $ncr = Nonconformance::query()
                ->where('company_id', $companyId)
                ->where('id', $nonconformanceId)
                ->lockForUpdate()
                ->first();

            if ($ncr === null) {
                throw new NotFoundException('Nonconformance not found.');
            }

            if ($ncr->status === 'closed') {
                throw new BusinessRuleException('Nonconformance is already closed.');
            }

            $ncr->status = 'closed';
            $ncr->save();

            $this->auditWriter->write(
                action: 'nonconformance.closed',
                entityType: 'nonconformance',
                entityId: $ncr->id,
                detail: [
                    'ncr_no' => $ncr->ncr_no,
                    'note' => $note,
                ],
                actorId: $actorId,
            );

            return [
                'id' => $ncr->id,
                'ncrNo' => $ncr->ncr_no,
                'status' => $ncr->status,
            ];
        });
    }
}
