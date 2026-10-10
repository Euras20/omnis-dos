<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Deletes completed outbox rows older than retention days.
 * Audit trail remains in audit_event; this is operational housekeeping only.
 * Cadence: monthly.
 */
final class PurgeOldCompletedOutbox implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $retentionDays = 90,
    ) {
    }

    public function handle(): void
    {
        $cutoff = now()->subDays($this->retentionDays);

        $deleted = DB::table('outbox')
            ->where('status', 'completed')
            ->where('processed_at', '<', $cutoff)
            ->delete();

        if ($deleted > 0) {
            Log::info('PurgeOldCompletedOutbox deleted rows', [
                'count' => $deleted,
                'retention_days' => $this->retentionDays,
            ]);
        }
    }
}
