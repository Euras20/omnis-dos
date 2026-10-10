<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Shared\Outbox\OutboxDispatcher;
use Illuminate\Console\Command;

/**
 * Continuous outbox drain loop for the single worker process.
 *
 * Usage: php artisan omnis:outbox-work
 *        php artisan omnis:outbox-work --once --limit=50
 *
 * In production run under a process supervisor (systemd / Docker / ECS).
 * Prefer this over schedule-only ProcessOutbox for low-latency side-effects.
 */
final class OutboxWorkCommand extends Command
{
    protected $signature = 'omnis:outbox-work
                            {--once : Process a single batch and exit}
                            {--limit=50 : Max rows per claim batch}
                            {--sleep=2 : Seconds to sleep between empty batches}';

    protected $description = 'Drain the transactional outbox (worker entrypoint)';

    public function handle(OutboxDispatcher $dispatcher): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $sleep = max(0, (int) $this->option('sleep'));
        $once = (bool) $this->option('once');

        $this->info("Outbox worker started (limit={$limit}, once=".($once ? 'yes' : 'no').')');

        do {
            $completed = $dispatcher->dispatch($limit);

            if ($completed > 0) {
                $this->line(now()->toIso8601String()." completed={$completed}");
            } elseif (! $once && $sleep > 0) {
                sleep($sleep);
            }
        } while (! $once);

        return self::SUCCESS;
    }
}
