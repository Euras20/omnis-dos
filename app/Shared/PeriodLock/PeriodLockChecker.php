<?php

declare(strict_types=1);

namespace App\Shared\PeriodLock;

use App\Exceptions\PeriodLockedException;
use App\Shared\Company\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Checks whether an accounting period is locked for a business date.
 *
 * Sources (defensive until foundation migrations exist):
 * - company.period_lock_date (global lock watermark)
 * - period_close rows with status = closed that cover the date
 *
 * Database triggers remain the final backstop for any missed application check.
 */
final class PeriodLockChecker
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
    }

    /**
     * @param  string  $businessDate  YYYY-MM-DD
     */
    public function isLocked(string $businessDate): bool
    {
        if (! config('omnis.period_lock.enforced', true)) {
            return false;
        }

        if (! $this->companyContext->isResolved()) {
            return false;
        }

        $companyId = $this->companyContext->companyId();

        try {
            $lockDate = DB::table('company')
                ->where('id', $companyId)
                ->value('period_lock_date');

            if ($lockDate !== null && $businessDate <= $lockDate) {
                return true;
            }
        } catch (\Throwable) {
            // Table may not exist yet (pre-14.4). Treat as open.
        }

        try {
            return DB::table('period_close')
                ->where('company_id', $companyId)
                ->where('status', 'closed')
                ->where('period_end', '>=', $businessDate)
                ->where('period_start', '<=', $businessDate)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Throw when the date is locked. Prefer this in command Actions.
     *
     * @param  string  $businessDate  YYYY-MM-DD
     *
     * @throws PeriodLockedException
     */
    public function assertOpen(string $businessDate): void
    {
        if ($this->isLocked($businessDate)) {
            throw PeriodLockedException::forDate($businessDate);
        }
    }
}
