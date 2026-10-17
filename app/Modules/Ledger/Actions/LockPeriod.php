<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\PeriodClose;
use App\Modules\Ledger\Queries\PeriodCloseChecklistQuery;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * POST /api/v1/close/{month}/lock
 *
 * Approve the close and set company.period_lock_date to the exact lock date
 * (defaults to the period end). PeriodLockChecker reads that watermark on
 * every subsequent command. Blocked while any check is still an open exception.
 * Stores the close snapshot in period_close (status = closed).
 */
final class LockPeriod
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
        private readonly PeriodCloseChecklistQuery $checklistQuery,
    ) {
    }

    /**
     * @param  array{lockThrough?: string}  $input
     * @return array<string, mixed>
     */
    public function execute(string $month, array $input = []): array
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
        $checklist = $this->checklistQuery->execute($month);

        if ($checklist['isLocked']) {
            throw new BusinessRuleException('This period is already locked.', 'already_locked');
        }
        if ($checklist['openExceptions'] > 0) {
            throw new BusinessRuleException(
                sprintf('%d exception(s) must be resolved or waived before locking.', $checklist['openExceptions']),
                'open_exceptions'
            );
        }

        $lockThrough = isset($input['lockThrough']) ? (string) $input['lockThrough'] : $checklist['periodEnd'];
        if ($lockThrough < $checklist['periodStart'] || $lockThrough > $checklist['periodEnd']) {
            throw new BusinessRuleException(
                'Lock date must fall within the period.',
                'invalid_lock_date'
            );
        }

        return DB::transaction(function () use ($companyId, $actorId, $month, $checklist, $lockThrough) {
            DB::table('company')
                ->where('id', $companyId)
                ->update([
                    'period_lock_date' => $lockThrough,
                    'updated_at' => now(),
                ]);

            $waived = 0;
            foreach ($checklist['checks'] as $check) {
                if ($check['state'] === 'Waived') {
                    $waived++;
                }
            }

            $row = PeriodClose::query()
                ->where('company_id', $companyId)
                ->where('period_month', $month)
                ->first();

            $payload = [
                'status' => 'closed',
                'period_start' => $checklist['periodStart'],
                'period_end' => $checklist['periodEnd'],
                'locked_through' => $lockThrough,
                'checks_snapshot' => $checklist['checks'],
                'waived_count' => $waived,
                'locked_by' => $actorId,
                'locked_at' => now(),
                'reopened_by' => null,
                'reopened_at' => null,
                'reopen_reason' => null,
            ];

            if ($row === null) {
                $row = PeriodClose::query()->create(array_merge($payload, [
                    'id' => (string) Str::uuid(),
                    'company_id' => $companyId,
                    'period_month' => $month,
                ]));
            } else {
                $row->update($payload);
            }

            $this->auditWriter->write('period.locked', 'period_close', $row->id, [
                'month' => $month,
                'lockedThrough' => $lockThrough,
                'waivedCount' => $waived,
            ]);

            return [
                'month' => $month,
                'status' => 'closed',
                'lockedThrough' => $lockThrough,
                'waivedCount' => $waived,
                'lockedBy' => $actorId,
                'lockedAt' => $row->locked_at?->toIso8601String(),
            ];
        });
    }
}
