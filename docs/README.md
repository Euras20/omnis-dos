# OMNIS API contract

Canonical OpenAPI 3.1 document: **`openapi.json`**.

| Item | Value |
|------|--------|
| Spec version | OpenAPI 3.1 |
| Runtime URL | `GET /api/v1/openapi.json` |
| Source of truth | PHP routes + Form Requests + controllers (this file is the committed artefact) |
| Generation | Hand-maintained for Step 14.14; future: `dedoc/scramble` or `l5-swagger` from Form Requests |

## Conventions reflected in the contract

- Money as integers in minor units (`*Minor` fields).
- Business dates as `YYYY-MM-Date`.
- UUIDs for resource IDs.
- `Idempotency-Key` required on every command (`POST` / `PUT` / `PATCH` / `DELETE`).
- Company context from auth middleware (clients never send `companyId`).
- Canonical error shape: `{ "error", "message", "fields?", "requestId" }`.

## CI

The `openapi` job in `.github/workflows/ci.yml` asserts:

1. `docs/openapi.json` exists and is valid JSON.
2. OpenAPI version is 3.1.
3. Core paths (`/health/live`, `/me`, `/invoices`) and the `Error` schema are present.

When automatic generation is introduced, CI should regenerate and fail on drift against the committed file.

## Consumers

- Import into Postman / Insomnia / Swagger UI.
- Client generators (TypeScript, PHP, etc.) if needed.
- The frontend review build is **not** driven by this file; the backend contract must still match what the UI calls.
