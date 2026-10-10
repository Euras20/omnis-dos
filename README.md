# OMNIS · DOS Limited

Modular monolith ERP backend for DOS Limited.

## Architecture sources of truth

| Document | Purpose |
|----------|---------|
| `../OMNIS · DOS Limited · Review build 7.html` | Frontend = scope of behaviour |
| `../01-domain-modules.md` … `../13-scalability-strategy.md` | Locked architecture decisions (Steps 1–13) |
| `../14-implementation-progress.md` | Step 14 progress tracker |
| `../OMNIS_Coding_Instructions_Step_14.md` | How to write the code |

## Stack

- **Laravel** modular monolith (PHP 8.3+)
- **PostgreSQL 16**
- One **API** process + one **worker** process
- Money as **BIGINT minor units** (pesewas)
- External IdP only in production (Cloudflare Access JWT / Entra Bearer)
- Dev auth: `x-dev-user: <email>` header

## Project layout

```
omnis-dos/
├── app/
│   ├── Modules/          # Domain modules (Identity, Catalog, Sales, …)
│   ├── Shared/           # Thin shared kernel (Money, Outbox, PeriodLock, …)
│   ├── Http/             # Controllers, Middleware, Requests, Resources
│   ├── Models/           # Thin Eloquent models + concerns
│   ├── Jobs/             # Queue jobs (outbox consumers)
│   └── Exceptions/       # Domain exception hierarchy
├── config/
│   ├── omnis.php
│   └── modules.php
├── database/migrations/  # Ordered migration groups
├── docs/
│   ├── openapi.json      # OpenAPI 3.1 contract (canonical)
│   └── README.md
├── routes/api.php        # /api/v1
├── tests/                # Unit / Feature / Integration
├── worker/README.md      # Worker ops runbook
└── .github/workflows/ci.yml
```

Full layout: see `04-folder-structure.md`.

## Local setup

```bash
cp .env.example .env
composer install
php artisan key:generate

# Configure PostgreSQL in .env (DB_*), then:
php artisan migrate

# API
php artisan serve
```

### Auth in development

Set `OMNIS_AUTH_DRIVER=dev` and send:

```http
x-dev-user: admin@example.com
```

The user must exist in `app_user` for the configured company.

### Worker (outbox side-effects)

```bash
# Continuous worker (preferred)
php artisan omnis:outbox-work

# Or one-shot
php artisan omnis:outbox-work --once --limit=50

# Stats / release stale locks
php artisan omnis:outbox-stats
php artisan omnis:outbox-release-stale
```

See `worker/README.md` for ops details. Scheduler entries live in `routes/console.php`.

## API contract

- **OpenAPI 3.1:** `docs/openapi.json`
- **Runtime:** `GET /api/v1/openapi.json`
- **Health:** `GET /api/v1/health/live`, `GET /api/v1/health/ready`

Key conventions (also documented in the OpenAPI `info` block):

| Topic | Rule |
|-------|------|
| Base path | `/api/v1` |
| Money | Integers in minor units; field names end in `Minor` |
| Dates | Business dates as `YYYY-MM-DD` |
| Commands | Require `Idempotency-Key` header (min 8 chars; prefer UUID) |
| Company | Injected from authenticated user; never sent by client |
| Errors | `{ "error", "message", "fields?", "requestId" }` |
| Concurrency | Editable masters carry `rowVersion`; send back on PATCH |

## Testing

```bash
# Unit + Feature (needs PostgreSQL test DB)
vendor/bin/phpunit --testsuite Unit,Feature

# Integration (outbox dispatcher)
vendor/bin/phpunit --testsuite Integration

# Or
composer test
```

Factories and helpers live under `tests/Support` and `database/factories`. See `tests/README.md`.

## CI gates

GitHub Actions (`.github/workflows/ci.yml`):

1. **OpenAPI** — `docs/openapi.json` exists, valid JSON, OpenAPI 3.1, core paths present.
2. **Pint** — `vendor/bin/pint --test` (style).
3. **PHPUnit** — Unit + Feature + Integration against PostgreSQL 16 service.

## Coding rules (summary)

1. Do **not** add features that are not present in the frontend review build.
2. Follow `OMNIS_Coding_Instructions_Step_14.md`.
3. Controllers stay thin; business logic lives in module **Actions** / **Services**.
4. All monetary values are integers in minor units.
5. Every command is company-scoped and respects **period lock** + **SoD** where required.
6. One command = one DB transaction (aggregates + journal + audit + outbox).
7. Side-effects only via transactional outbox; never roll back posted money/stock on worker failure.

## Module map (high level)

| Module | Responsibility |
|--------|----------------|
| Identity | Users, roles, permissions, /me, access review |
| Catalog | Parties, items, locations, tax/WHT, FX, templates |
| Ledger | COA, journals, **sole poster** (PostingService) |
| Receivables | Invoices, receipts, credit notes, refunds |
| Payables | Bills, supplier payments (prepare / confirm / cancel) |
| Inventory | Stock movements, assignments, deliveries, on-hand |
| Sales | Quotations, sales orders |
| Procurement | POs, GRNs, QC |
| Payroll | Employees, payroll runs |
| Assets | Fixed assets, depreciation runs |
| Treasury | Cash expenses, transfers |
| Shared | Money, CompanyContext, Idempotency, Audit, Outbox, PeriodLock, PermissionChecker |

## Status

Step 14 (implement clean maintainable code) is complete through **14.14 OpenAPI + final polish**.
