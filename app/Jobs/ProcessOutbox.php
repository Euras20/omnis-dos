<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Shared\Outbox\OutboxDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Drains a batch of pending outbox rows.
 *
 * Prefer continuous worker via `php artisan omnis:outbox-work` in production.
 * This job is scheduled as a safety net / alternative entry when using
 * schedule:run only.
 */
final class ProcessOutbox implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $limit = 50,
    ) {
        $this->onQueue(config('omnis.outbox.queue', 'outbox'));
    }

    public function handle(OutboxDispatcher $dispatcher): void
    {
        $completed = $dispatcher->dispatch($this->limit);

        if ($completed > 0) {
            Log::info('ProcessOutbox completed rows', ['count' => $completed]);
        }
    }
}
