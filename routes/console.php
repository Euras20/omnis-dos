<?php

declare(strict_types=1);

use App\Jobs\DepreciationDueReminder;
use App\Jobs\OutboxHealthReport;
use App\Jobs\PeriodCloseCheckReminder;
use App\Jobs\ProcessOutbox;
use App\Jobs\PurgeOldCompletedOutbox;
use App\Jobs\ReleaseStaleOutboxLocks;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| OMNIS Console Schedule
|--------------------------------------------------------------------------
|
| Single system cron: * * * * * php artisan schedule:run
|
| Primary outbox drain should run as a dedicated worker:
|   php artisan omnis:outbox-work
|
| ProcessOutbox is scheduled as a low-frequency safety net only.
|
| Source: 09-events-jobs-queues.md §9.9
*/

// Safety-net drain if the continuous worker is temporarily down.
Schedule::job(new ProcessOutbox(limit: 50))
    ->everyMinute()
    ->withoutOverlapping(2)
    ->name('omnis-outbox-drain');

// Return abandoned processing locks to pending.
Schedule::job(new ReleaseStaleOutboxLocks(staleMinutes: 15))
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->name('omnis-outbox-release-stale');

// Period-close reminder in the last days of the month (job self-gates).
Schedule::job(new PeriodCloseCheckReminder)
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->name('omnis-period-close-reminder');

// Depreciation due reminder (job self-gates on assets + existing run).
Schedule::job(new DepreciationDueReminder)
    ->monthlyOn(25, '08:00')
    ->withoutOverlapping()
    ->name('omnis-depreciation-due-reminder');

// Weekly dead-letter / backlog report.
Schedule::job(new OutboxHealthReport)
    ->weeklyOn(1, '07:00')
    ->withoutOverlapping()
    ->name('omnis-outbox-health');

// Housekeeping: purge completed outbox rows older than retention.
Schedule::job(new PurgeOldCompletedOutbox(retentionDays: 90))
    ->monthlyOn(1, '03:00')
    ->withoutOverlapping()
    ->name('omnis-outbox-purge');
