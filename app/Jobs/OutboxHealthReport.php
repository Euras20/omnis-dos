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
 * Surfaces dead / high-attempt outbox rows for ops review.
 * Cadence: weekly.
 */
final class OutboxHealthReport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function handle(): void
    {
        $dead = (int) DB::table('outbox')->where('status', 'dead')->count();
        $highAttempts = (int) DB::table('outbox')
            ->where('status', 'pending')
            ->where('attempts', '>=', 5)
            ->count();
        $pending = (int) DB::table('outbox')->where('status', 'pending')->count();
        $processing = (int) DB::table('outbox')->where('status', 'processing')->count();

        $level = ($dead > 0 || $highAttempts > 10) ? 'warning' : 'info';

        Log::{$level}('OutboxHealthReport', [
            'pending' => $pending,
            'processing' => $processing,
            'dead' => $dead,
            'high_attempts' => $highAttempts,
        ]);
    }
}
