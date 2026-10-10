<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class OutboxStatsCommand extends Command
{
    protected $signature = 'omnis:outbox-stats';

    protected $description = 'Show outbox row counts by status';

    public function handle(): int
    {
        $rows = DB::table('outbox')
            ->select('status', DB::raw('count(*) as cnt'))
            ->groupBy('status')
            ->orderBy('status')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Outbox is empty.');

            return self::SUCCESS;
        }

        $this->table(['status', 'count'], $rows->map(fn ($r) => [$r->status, $r->cnt]));

        $dead = DB::table('outbox')
            ->where('status', 'dead')
            ->orderByDesc('processed_at')
            ->limit(10)
            ->get(['id', 'event_type', 'attempts', 'last_error', 'processed_at']);

        if ($dead->isNotEmpty()) {
            $this->newLine();
            $this->warn('Recent dead-letter rows (max 10):');
            $this->table(
                ['id', 'event_type', 'attempts', 'last_error', 'processed_at'],
                $dead->map(fn ($r) => [
                    $r->id,
                    $r->event_type,
                    $r->attempts,
                    mb_substr((string) $r->last_error, 0, 80),
                    $r->processed_at,
                ])
            );
        }

        return self::SUCCESS;
    }
}
