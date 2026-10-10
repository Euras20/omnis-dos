# OMNIS Tests

Source of truth: `artifacts/12-testing-strategy.md`.

## Requirements

- **PostgreSQL 16** test database (schema uses plpgsql triggers, deferred constraints, `SKIP LOCKED`).
- Configure via environment or `phpunit.xml`:

```bash
export DB_CONNECTION=pgsql
export DB_HOST=127.0.0.1
export DB_PORT=5432
export DB_DATABASE=omnis_test
export DB_USERNAME=omnis
export DB_PASSWORD=
```

Create the database once:

```bash
createdb -U omnis omnis_test
```

## Auth in tests

`OMNIS_AUTH_DRIVER=dev` is forced in `TestCase::setUp()`.  
Authenticated requests use header `X-Dev-User: <user email>`.

Helpers live in `tests/Support/OmnisTestHelpers.php`:

- `seedCompanyAndUser(permissions, periodLockDate?)`
- `omnisGet` / `omnisPost` / `omnisPatch`
- `assertJournalBalanced`, `assertAuditEventExists`, `assertOutboxEventExists`

## Suites

| Suite | Path | Focus |
|-------|------|-------|
| Unit | `tests/Unit` | Money, PeriodLockChecker, PermissionChecker (no HTTP) |
| Feature | `tests/Feature/{Module}` | HTTP → Action → DB oracle for commands |
| Integration | `tests/Integration` | Outbox dispatcher claim/process |

## Run

```bash
composer install
php artisan migrate --env=testing   # or rely on RefreshDatabase
./vendor/bin/phpunit
./vendor/bin/phpunit --testsuite Unit
./vendor/bin/phpunit --filter ManualJournalTest
```

## P0 coverage (this step)

- Shared: Money VO, PeriodLockChecker, PermissionChecker (deny wins)
- Identity: `GET /me`
- Ledger: create + post manual journal (happy, forbidden, period lock, SoD, unbalanced, idempotency)
- Receivables: post invoice (happy, forbidden, idempotency)
- Inventory: on-hand query permission surface
- Integration: outbox dispatch empty + seeded row

Expand Feature coverage for Payables, Procurement GRN, Payroll, and concurrent allocation races as regressions appear.
