# OMNIS Worker Process

Single background worker process that shares the API codebase and database.

## Primary entrypoint (recommended)

```bash
php artisan omnis:outbox-work
```

Options:

| Flag | Default | Meaning |
|------|---------|---------|
| `--once` | off | Process one batch and exit |
| `--limit=N` | 50 | Rows claimed per batch |
| `--sleep=N` | 2 | Seconds between empty polls |

Run under a process supervisor (systemd, Docker `restart: always`, ECS, etc.).

## Alternative: queue worker + scheduler

If you prefer Laravel's queue abstraction:

```bash
php artisan queue:work --queue=outbox,default --sleep=1 --tries=1
```

Plus system cron:

```
* * * * * cd /path/to/omnis-dos && php artisan schedule:run >> /dev/null 2>&1
```

The schedule registers `ProcessOutbox`, `ReleaseStaleOutboxLocks`, period/depreciation reminders, health report and purge (see `routes/console.php`).

## Ops commands

```bash
php artisan omnis:outbox-stats              # counts by status + recent dead letters
php artisan omnis:outbox-release-stale      # force release abandoned locks
php artisan omnis:outbox-work --once        # one-shot drain (useful in CI / smoke)
```

## Contract

- Side-effects are **at-least-once**. Handlers must be idempotent.
- Business state (journals, stock, open balances, audit) is **never** rolled back by worker failure.
- Dead-letter rows (`status = dead`) are reviewed manually; re-queue by setting `status = pending`, clearing locks, and setting `available_at = now()`.

See `09-events-jobs-queues.md` for the full design.
