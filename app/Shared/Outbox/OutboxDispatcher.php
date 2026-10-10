<?php

declare(strict_types=1);

namespace App\Shared\Outbox;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Claims and dispatches pending outbox rows.
 *
 * Runs in the single worker process (or multiple workers with SKIP LOCKED).
 * Handlers are registered by event_type and must be idempotent
 * (at-least-once delivery).
 *
 * Full job wiring and console entry points arrive in Step 14.12;
 * this class is the pure claim + dispatch core of the shared kernel.
 */
final class OutboxDispatcher
{
    /** @var array<string, callable(array): void> */
    private array $handlers = [];

    private string $workerId;

    public function __construct()
    {
        $this->workerId = gethostname().':'.getmypid();
    }

    /**
     * Register a handler for an event_type.
     * Handler receives the decoded payload array and must be idempotent.
     */
    public function register(string $eventType, callable $handler): void
    {
        $this->handlers[$eventType] = $handler;
    }

    /**
     * Claim up to $limit pending rows and process them.
     * Returns the number of rows successfully completed.
     */
    public function dispatch(int $limit = 50): int
    {
        $completed = 0;
        $rows = $this->claim($limit);

        foreach ($rows as $row) {
            try {
                $this->process($row);
                $this->markCompleted((string) $row->id);
                $completed++;
            } catch (Throwable $e) {
                $this->markFailed((string) $row->id, $e);
            }
        }

        return $completed;
    }

    /**
     * @return list<object>
     */
    private function claim(int $limit): array
    {
        return DB::transaction(function () use ($limit) {
            $rows = DB::select(
                'SELECT * FROM outbox
                 WHERE status = ?
                   AND available_at <= ?
                 ORDER BY available_at
                 LIMIT ?
                 FOR UPDATE SKIP LOCKED',
                ['pending', now(), $limit]
            );

            $ids = array_map(fn ($r) => $r->id, $rows);

            if ($ids !== []) {
                DB::table('outbox')
                    ->whereIn('id', $ids)
                    ->update([
                        'status' => 'processing',
                        'locked_at' => now(),
                        'locked_by' => $this->workerId,
                        'attempts' => DB::raw('attempts + 1'),
                    ]);
            }

            return $rows;
        });
    }

    private function process(object $row): void
    {
        $handler = $this->handlers[$row->event_type] ?? null;

        if ($handler === null) {
            throw new \RuntimeException("No handler registered for event_type [{$row->event_type}]");
        }

        $payload = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);

        $handler($payload);
    }

    private function markCompleted(string $id): void
    {
        DB::table('outbox')->where('id', $id)->update([
            'status' => 'completed',
            'processed_at' => now(),
            'locked_at' => null,
            'locked_by' => null,
            'last_error' => null,
        ]);
    }

    private function markFailed(string $id, Throwable $e): void
    {
        $attempts = (int) DB::table('outbox')->where('id', $id)->value('attempts');
        $maxAttempts = 8;
        $isDead = $attempts >= $maxAttempts;

        // Exponential backoff: 2^attempts minutes, capped.
        $delayMinutes = min(2 ** min($attempts, 8), 240);

        DB::table('outbox')->where('id', $id)->update([
            'status' => $isDead ? 'dead' : 'pending',
            'available_at' => $isDead ? now() : now()->addMinutes($delayMinutes),
            'locked_at' => null,
            'locked_by' => null,
            'last_error' => mb_substr($e->getMessage(), 0, 2000),
            'processed_at' => $isDead ? now() : null,
        ]);

        Log::warning('Outbox handler failed', [
            'outbox_id' => $id,
            'attempts' => $attempts,
            'dead' => $isDead,
            'error' => $e->getMessage(),
        ]);
    }
}
