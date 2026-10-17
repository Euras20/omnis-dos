<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\PeriodClose;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/close/{month}/reopen
 *
 * Reopen a locked period. Sets company.period_lock_date to the day before the
 * month starts (the watermark PeriodLockChecker consults) and marks the
 * period_close row reopened. A reason is required.
 */
final class ReopenPeriod
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @param  array{reason: string}  $input
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

        $reason = trim((string) ($input['reason'] ?? ''));
        if (strlen($reason) < 10) {
            throw new BusinessRuleException('Reopen reason must be at least 10 characters.', 'invalid_reason');
        }

        $companyId = $this->companyContext->companyId();
        $start = $month.'-01';
        $newLock = (new \DateTimeImmutable($start))->modify('-1 day')->format('Y-m-d');

        return DB::transaction(function () use ($companyId, $actorId, $month, $newLock, $reason) {
            $row = PeriodClose::query()
                ->where('company_id', $companyId)
                ->where('period_month', $month)
                ->first();

            $currentLock = DB::table('company')
                ->where('id', $companyId)
                ->value('period_lock_date');

            if ($currentLock === null || (string) $currentLock < $start) {
                throw new BusinessRuleException('This period is not locked.', 'not_locked');
            }

            DB::table('company')
                ->where('id', $companyId)
                ->update([
                    'period_lock_date' => $newLock,
                    'updated_at' => now(),
                ]);

            if ($row !== null) {
                $row->update([
                    'status' => 'reopened',
                    'reopened_by' => $actorId,
                    'reopened_at' => now(),
                    'reopen_reason' => $reason,
                ]);
            }

            $this->auditWriter->write('period.reopened', 'period_close', $row?->id, [
                'month' => $month,
                'newLockThrough' => $newLock,
                'reason' => $reason,
            ]);

            return [
                'month' => $month,
                'status' => 'reopened',
                'lockedThrough' => $newLock,
                'reason' => $reason,
                'reopenedBy' => $actorId,
            ];
        });
    }
}
