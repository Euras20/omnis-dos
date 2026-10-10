<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ReleaseStaleOutboxLocks;
use Illuminate\Console\Command;

final class ReleaseStaleOutboxLocksCommand extends Command
{
    protected $signature = 'omnis:outbox-release-stale
                            {--minutes=15 : Age in minutes after which a processing lock is stale}';

    protected $description = 'Return abandoned outbox processing rows to pending';

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));

        ReleaseStaleOutboxLocks::dispatchSync($minutes);

        $this->info("Stale outbox locks release attempted (threshold={$minutes}m).");

        return self::SUCCESS;
    }
}
