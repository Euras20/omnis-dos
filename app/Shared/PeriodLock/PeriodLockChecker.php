<?php

declare(strict_types=1);

namespace App\Shared\PeriodLock;

use App\Exceptions\PeriodLockedException;
use App\Shared\Company\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Checks whether an accounting period is locked for a business date.
 *
 * Sources:
 * - company.period_lock_date (global lock watermark)
 * - period_close rows with status = closed that cover the date (when table exists)
 *
 * Database triggers remain the final backstop for any missed application check.
 *
 * IMPORTANT: Never issue a query that can fail (e.g. missing table) inside an
 * open business transaction. In PostgreSQL any error aborts the transaction
 * (SQLSTATE 25P02); catching the exception in PHP does not undo that.
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

        // company table is part of foundation migrations — always present in
        // a running app. A failure here is a real problem; do not swallow it.
        $lockDate = DB::table('company')
            ->where('id', $companyId)
            ->value('period_lock_date');

        if ($lockDate !== null && $businessDate <= (string) $lockDate) {
            return true;
        }

        // period_close is documented but not yet migrated in all environments.
        // Check existence first so we never raise "relation does not exist"
        // inside an open transaction (which would abort the TX in Postgres).
        if (! Schema::hasTable('period_close')) {
            return false;
        }

        return DB::table('period_close')
            ->where('company_id', $companyId)
            ->where('status', 'closed')
            ->where('period_end', '>=', $businessDate)
            ->where('period_start', '<=', $businessDate)
            ->exists();
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
