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
 * Returns abandoned processing rows to pending so another worker can claim them.
 *
 * Visibility timeout safety net for crashed workers.
 * Cadence: every 5–15 minutes (scheduler).
 */
final class ReleaseStaleOutboxLocks implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $staleMinutes = 15,
    ) {
        $this->onQueue(config('omnis.outbox.queue', 'outbox'));
    }

    public function handle(): void
    {
        $threshold = now()->subMinutes($this->staleMinutes);

        $released = DB::table('outbox')
            ->where('status', 'processing')
            ->where('locked_at', '<', $threshold)
            ->update([
                'status' => 'pending',
                'locked_at' => null,
                'locked_by' => null,
                'available_at' => now(),
                'last_error' => DB::raw(
                    "COALESCE(last_error, '') || ' [stale lock released]'"
                ),
            ]);

        if ($released > 0) {
            Log::warning('ReleaseStaleOutboxLocks released rows', [
                'count' => $released,
                'stale_minutes' => $this->staleMinutes,
            ]);
        }
    }
}
