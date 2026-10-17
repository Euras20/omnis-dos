<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\CloseWaiver;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * POST /api/v1/close/{month}/waivers
 *
 * Record a close_waiver for one exception. The checklist then reports that
 * check as Waived so the period can be locked.
 */
final class WaiveCloseException
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @param  array{exceptionCode: string, reason: string, evidenceRef?: string}  $input
     * @return array<string, mixed>
     */
    public function execute(string $month, array $input): array
    {
        $actorId = $this->companyContext->userId();
        if (! $this->permissionChecker->can($actorId, 'period.close')
            && ! $this->permissionChecker->can($actorId, 'close.manage')) {
            $this->permissionChecker->assertCan($actorId, 'period.close');
        }

        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new BusinessRuleException('Month must be YYYY-MM.', 'invalid_month');
        }

        $companyId = $this->companyContext->companyId();
        $code = (string) $input['exceptionCode'];
        $reason = trim((string) $input['reason']);
        if (strlen($reason) < 10) {
            throw new BusinessRuleException('Waiver reason must be at least 10 characters.', 'invalid_reason');
        }

        return DB::transaction(function () use ($companyId, $actorId, $month, $code, $reason, $input) {
            $existing = CloseWaiver::query()
                ->where('company_id', $companyId)
                ->where('period_month', $month)
                ->where('exception_code', $code)
                ->first();

            if ($existing !== null) {
                $existing->update([
                    'reason' => $reason,
                    'evidence_ref' => $input['evidenceRef'] ?? null,
                    'waived_by' => $actorId,
                    'waived_at' => now(),
                ]);
                $waiver = $existing;
            } else {
                $waiver = CloseWaiver::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $companyId,
                    'period_month' => $month,
                    'exception_code' => $code,
                    'reason' => $reason,
                    'evidence_ref' => $input['evidenceRef'] ?? null,
                    'waived_by' => $actorId,
                    'waived_at' => now(),
                ]);
            }

            $this->auditWriter->write('close.exception.waived', 'close_waiver', $waiver->id, [
                'month' => $month,
                'exceptionCode' => $code,
                'reason' => $reason,
            ]);

            return [
                'id' => $waiver->id,
                'month' => $month,
                'exceptionCode' => $code,
                'reason' => $reason,
                'evidenceRef' => $waiver->evidence_ref,
                'waivedBy' => $actorId,
                'waivedAt' => $waiver->waived_at?->toIso8601String(),
            ];
        });
    }
}
