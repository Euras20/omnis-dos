# OMNIS · API Payload Reference (for Frontend)

**Base URL:** `/api/v1`  
**Content-Type:** `application/json`  
**Source:** Implemented routes + Form Requests in `omnis-dos/` (includes Step 16).  
**Related:** `omnis-dos/docs/openapi.json` (machine-readable contract).

---

## 1. Global conventions (read this first)

### Headers on every request

| Header | Required | When | Example |
|--------|----------|------|---------|
| `Content-Type` | Yes (body) | POST / PUT / PATCH | `application/json` |
| `Idempotency-Key` | **Yes** | Every **command** (POST / PUT / PATCH / DELETE) | `550e8400-e29b-41d4-a716-446655440000` |
| Auth | Yes | All except health + openapi | See below |

**Auth (pick one):**

| Environment | Header |
|-------------|--------|
| Production | `Cf-Access-Jwt-Assertion: <jwt>` **or** `Authorization: Bearer <token>` |
| Local / dev | `x-dev-user: admin@example.com` |

**Idempotency-Key rules**

- Generate a new UUID when the user opens a form / starts an action.
- Same key + same body → server returns the **original** response and header `Idempotent-Replay: true`.
- Same key + **different** body → `409` with `error: "idempotency_mismatch"`.
- Do **not** reuse a key for a different business action.

### Money

- Always **integers in minor units** (pesewas).  
  GHS `1,234.56` → `123456`
- Field names end in `Minor` (e.g. `unitPriceMinor`, `amountMinor`, `cashMinor`).
- Never send decimals for money.

### Dates

- Business dates: `YYYY-MM-DD` (e.g. `"2026-10-09"`).

### IDs

- Resource IDs are **UUIDs** (strings).
- Human numbers (invoice number, journal number) are separate string fields on responses.

### Company context

- Do **not** send `companyId`. The server takes it from the authenticated user.

### Success vs error shape

**Success** — body is the resource (or list). Status `200` / `201` / `204`.

**Error** (any non-2xx):

```json
{
  "error": "period_locked",
  "message": "The accounting period for 2026-09-30 is locked.",
  "fields": null,
  "requestId": "a1b2c3d4-e5f6-7890-abcd-ef1234567890"
}
```

| Field | Meaning |
|-------|---------|
| `error` | Machine code (use for if/else) |
| `message` | Human text (show to user) |
| `fields` | Optional map of field → messages (validation) |
| `requestId` | Correlation id (support / logs) |

**Common error codes**

| HTTP | `error` | Meaning |
|------|---------|---------|
| 401 | `unauthenticated` | Missing / bad auth |
| 403 | `forbidden` / `sod_violation` | No permission or same-actor SoD |
| 404 | `not_found` | Unknown id in this company |
| 409 | `period_locked` | Period closed |
| 409 | `stale_revision` | `rowVersion` mismatch (edit conflict) |
| 409 | `idempotency_mismatch` | Same Idempotency-Key, different body |
| 409 | `insufficient_stock` | Not enough stock |
| 422 | `validation_failed` | Bad payload; check `fields` |
| 500 | `internal_error` | Unexpected; use `requestId` |

---

## 2. Public (no auth)

### `GET /health/live`

No body.

**Expected 200**

```json
{ "status": "ok" }
```

### `GET /health/ready`

Same as live.

### `GET /openapi.json`

Returns the OpenAPI document (or 404 if missing).

---

## 3. Identity

### `GET /me`

No body. Who is signed in + effective permissions.

**Expected 200**

```json
{
  "id": "uuid",
  "email": "admin@example.com",
  "displayName": "Admin User",
  "userType": "staff",
  "companyId": "uuid",
  "permissions": ["invoices.post", "journals.post"],
  "roles": ["admin"],
  "locationIds": []
}
```

### `GET /admin/permissions`

List permission catalogue. No body.

### `GET /admin/roles`

List roles. No body.

### `POST /admin/roles`

**Headers:** `Idempotency-Key`

**Payload**

```json
{
  "code": "accountant",
  "name": "Accountant",
  "description": "Can post journals and invoices",
  "approvalLimitMinor": 5000000,
  "permissions": ["journals.post", "invoices.post"],
  "copyFromCode": null
}
```

| Field | Required | Notes |
|-------|----------|-------|
| `code` | Yes | lowercase, `[a-z][a-z0-9_]*` |
| `name` | Yes | |
| `description` | No | |
| `approvalLimitMinor` | No | money minor units |
| `permissions` | No | array of permission codes |
| `copyFromCode` | No | clone another role’s permissions |

**Expected 201** — created role object.

### `PUT /admin/roles/{code}`

**Headers:** `Idempotency-Key`

**Payload**

```json
{
  "name": "Senior Accountant",
  "description": "Updated",
  "approvalLimitMinor": 10000000,
  "permissions": ["journals.post", "invoices.post", "bills.post"],
  "rowVersion": 1
}
```

`permissions` and `rowVersion` required on update.

### `DELETE /admin/roles/{code}`

**Headers:** `Idempotency-Key`  
No body. System roles cannot be deleted → `409`.

### `GET /admin/users`

Query: `limit`, `offset`, `q` (search).

### `POST /admin/users`

**Headers:** `Idempotency-Key`

**Payload**

```json
{
  "email": "jane@example.com",
  "displayName": "Jane Doe",
  "userType": "staff",
  "partyId": null,
  "jobTitle": "Accountant",
  "phone": "+233...",
  "externalSubject": null,
  "roleCodes": ["accountant"],
  "locationIds": []
}
```

**Expected 201** — user summary.

### `PATCH /admin/users/{id}`

**Headers:** `Idempotency-Key`

**Payload** (all optional)

```json
{
  "displayName": "Jane D.",
  "jobTitle": "Senior Accountant",
  "phone": null,
  "isActive": true,
  "roleCodes": ["accountant"],
  "locationIds": []
}
```

Cannot deactivate yourself / last admin → `409`.

### `PUT /admin/users/{id}/overrides`

**Headers:** `Idempotency-Key`

**Payload**

```json
{
  "overrides": [
    { "permission": "journals.post", "effect": "deny", "reason": "Temporary restriction" },
    { "permission": "reports.export", "effect": "grant", "reason": null }
  ]
}
```

Deny wins over role grants.

### `GET /admin/users/{id}/effective`

Effective permission list for that user. No body.

### `GET /admin/access-review`

Snapshot for access review screens. No body.

---

## 4. Catalog

### `GET /parties`

Query: `type` (`customer` \| `supplier` \| `both`), `q`, `limit`, `offset`.

**Who can list customers:** `customers.view`, `customers.manage`, **or** any sales quotation / sales order permission (`sales.quotes.view`, `sales.quotes.manage`, `sales.orders.view`, `sales.orders.manage`). Sales roles can load the customer dropdown without full customer-master rights. Creating or editing customers still needs `customers.manage`.

### `POST /parties`

**Headers:** `Idempotency-Key`

**Payload**

```json
{
  "code": "CUST-001",
  "name": "Acme Trading Ltd",
  "partyType": "customer",
  "tin": "C1234567890",
  "regNo": null,
  "city": "Accra",
  "region": "Greater Accra",
  "country": "GH",
  "address": "12 Independence Ave",
  "gps": null,
  "contactName": "Kwame Mensah",
  "email": "ap@acme.example",
  "phone": "+233...",
  "paymentTerms": "Net 30",
  "creditLimitMinor": 50000000,
  "currency": "GHS"
}
```

Use `partyType` or `type` (`customer` \| `supplier` \| `carrier` \| `broker`).

**Expected 201** — party with `id`, `rowVersion`, etc.

### `GET /parties/{id}`

Party detail. No body.

### `GET /parties/{id}/statement` · *(session)*

Supplier AP statement. Path `{id}` = **supplier** party id.

**Query**

| Param | Required | Notes |
|-------|----------|--------|
| `from` | Yes | `YYYY-MM-DD` period start |
| `to` | No | Defaults to today |

**Example:** `GET /parties/{supplierId}/statement?from=2026-01-01&to=2026-10-31`

**Expected 200**

```json
{
  "supplierId": "uuid",
  "supplierCode": "S-001",
  "supplierName": "Acme Supplies",
  "from": "2026-01-01",
  "to": "2026-10-31",
  "openingMinor": 50000,
  "billedMinor": 120000,
  "paidMinor": 80000,
  "closingMinor": 90000,
  "lines": [
    {
      "date": "2026-02-10",
      "type": "bill",
      "ref": "BILL-2026-00012",
      "documentId": "uuid",
      "supplierRef": "SUP-INV-1",
      "debitMinor": 120000,
      "creditMinor": 0,
      "memo": null
    },
    {
      "date": "2026-03-01",
      "type": "payment",
      "ref": "PAY-2026-00003",
      "documentId": "uuid",
      "supplierRef": "BANK-REF",
      "debitMinor": 0,
      "creditMinor": 80000,
      "memo": null
    }
  ]
}
```

Opening/closing use `bill_open()` (same as bill list open balances). Only **posted** bills and **confirmed** payments.

### `PATCH /parties/{id}`

**Headers:** `Idempotency-Key`  
Send `rowVersion` if you have it (optimistic concurrency). Same shape as create (partial).

### `GET /locations` · `POST /locations`

**POST payload**

```json
{
  "code": "MAIN",
  "name": "Main Store",
  "locType": "store",
  "warehouse": "WH-1",
  "keeperId": null,
  "isAssignable": true,
  "capacity": null
}
```

`locType` / `type`: `receiving` \| `quarantine` \| `rejected` \| `store` \| `dispatch`.

### `DELETE /locations/{id}`

**Headers:** `Idempotency-Key`

### `GET /item-categories` · `POST /item-categories` · `PATCH /item-categories/{id}`

**POST**

```json
{ "code": "RAW", "name": "Raw materials" }
```

**PATCH** — partial update. **Headers:** `Idempotency-Key`. Permission: `items.manage`.

```json
{
  "rowVersion": 1,
  "name": "Raw materials & components",
  "isActive": true
}
```

`rowVersion` required (optimistic concurrency). Optional: `code`, `name`, `isActive`. Duplicate `code` → `409`.

### `GET /items` · `POST /items` · `PATCH /items/{id}`

Query list: `q`, `type`, `category`, `status`, `limit`, `offset`.

**POST payload** (typical)

```json
{
  "sku": "ITEM-100",
  "name": "Widget A",
  "itemType": "Trading good",
  "categoryCode": "RAW",
  "unit": "pcs",
  "listPriceMinor": 15000,
  "standardCostMinor": 9000,
  "isActive": true
}
```

**PATCH** — partial update. **Headers:** `Idempotency-Key`. Permission: `items.manage`.

```json
{
  "rowVersion": 1,
  "name": "Widget A (revised)",
  "listPriceMinor": 16000,
  "standardCostMinor": 9500,
  "categoryCode": "RAW",
  "defaultLocationId": null,
  "defaultSupplierId": null,
  "isActive": true
}
```

`rowVersion` required. Optional fields match create (`sku`, `name`, `itemType`/`type`, `categoryCode`, `unit`, prices, `weightKg`, `dimensions`, location/supplier, inventory fields, `isActive`). Duplicate SKU → `409`. Stale `rowVersion` → `409` `stale_revision`.

### `GET /order-types` · `POST /order-types` · `PATCH /order-types/{id}`

**POST**

```json
{ "code": "STD", "name": "Standard", "workflow": null }
```

**PATCH** — partial update. **Headers:** `Idempotency-Key`. Permission: `items.manage` or `sales.orders.manage`.

```json
{
  "rowVersion": 1,
  "name": "Standard order",
  "workflow": "default",
  "isActive": true
}
```

`rowVersion` required. Optional: `code`, `name`, `workflow`, `isActive`. Duplicate `code` → `409`.

### `GET /tax/rules` · `POST /tax/rules`

**POST**

```json
{
  "code": "VAT",
  "name": "Standard VAT",
  "rateBps": 1250,
  "effectiveFrom": "2026-01-01",
  "effectiveTo": null
}
```

`rateBps` = basis points (1250 = 12.5%).

### `GET /tax/wht`

List WHT rules. No body.

### `GET /fx/rates` · `PUT /fx/rates/{date}`

**PUT** path `{date}` = `YYYY-MM-DD`.

```json
{
  "rates": [
    { "currency": "USD", "rate": 15.25 },
    { "currency": "EUR", "rate": 16.80 }
  ]
}
```

### Document templates

| Method | Path | Notes |
|--------|------|--------|
| GET | `/document-templates` | List |
| GET | `/document-templates/{type}` | One type |
| PUT | `/document-templates/{type}/draft` | Body: `{ "settings": { ... } }` + Idempotency-Key |
| DELETE | `/document-templates/{type}/draft` | Discard draft |
| POST | `/document-templates/{type}/publish` | Publish draft |

---

## 5. Ledger

### `GET /accounts`

Query: `q`, `type`.

### `POST /accounts`

**Headers:** `Idempotency-Key`

```json
{
  "code": "1100",
  "name": "Accounts Receivable",
  "accountType": "asset",
  "parentId": null,
  "isPostable": true
}
```

`accountType`: `asset` \| `liability` \| `equity` \| `revenue` \| `expense`.

### `PATCH /accounts/{id}`

**Headers:** `Idempotency-Key`  
Partial: `name`, `isPostable`, `isActive` + `rowVersion` if available.

### `GET /journals` · `GET /journals/{id}`

Query list: `from`, `to`, `status` (`draft` \| `posted` \| `reversed`), `limit`, `offset`.

### `POST /journals` (create draft)

**Headers:** `Idempotency-Key`

```json
{
  "postingDate": "2026-10-09",
  "description": "Manual accrual",
  "currency": "GHS",
  "lines": [
    {
      "accountId": "uuid-debit",
      "debitMinor": 100000,
      "creditMinor": 0,
      "partyId": null,
      "memo": "Expense"
    },
    {
      "accountId": "uuid-credit",
      "debitMinor": 0,
      "creditMinor": 100000,
      "partyId": null,
      "memo": "Accrual"
    }
  ]
}
```

- At least **2** lines.
- Debits must equal credits (enforced on **post**).
- Money fields are integers.

**Expected 201** — journal with `status: "draft"`.

### `POST /journals/{id}/post`

**Headers:** `Idempotency-Key`  
No body (or empty). Posts the draft → journal lines immutable; period lock applies.

**Expected 200** — journal with `status: "posted"`.

### `POST /journals/{id}/reverse`

**Headers:** `Idempotency-Key`

```json
{
  "postingDate": "2026-10-10",
  "description": "Reverse accrual"
}
```

Creates and posts a reversing journal.

---

## 6. Receivables

### `GET /invoices` · `GET /invoices/{id}`

Query: `customerId`, `status`, `from`, `to`, `q`, `limit`, `offset`.

### `POST /invoices` (post invoice)

**Headers:** `Idempotency-Key`

```json
{
  "customerId": "uuid",
  "invoiceDate": "2026-10-09",
  "dueDate": "2026-11-08",
  "currency": "GHS",
  "memo": "October services",
  "salesOrderId": "uuid-or-null",
  "lines": [
    {
      "description": "Consulting – Oct",
      "qty": 1,
      "unitPriceMinor": 500000,
      "itemId": null,
      "unit": null,
      "salesOrderLineId": "uuid-or-null"
    }
  ]
}
```

**Order billing rules**

- `salesOrderId` links the invoice header to the sales order (optional for ad-hoc invoices).
- When `salesOrderId` is set, **every line must include `salesOrderLineId`**.
- Server updates `sales_order_line.qty_invoiced` and **rejects** qty above remaining (`ordered − already invoiced`).
- Delivery is **not** required before invoicing (pre-delivery billing is allowed).
- Order must be `confirmed` (or `closed`).

**Expected 201** — invoice with lines (incl. `salesOrderLineId`), journal posted.

`GET /invoices` also accepts query `salesOrderId` and returns `salesOrderId` on each item.

### `POST /invoices/{id}/credit-notes`

**Headers:** `Idempotency-Key`

```json
{
  "creditDate": "2026-10-10",
  "memo": "Partial credit",
  "lines": [
    {
      "description": "Credit line",
      "qty": 1,
      "unitPriceMinor": 50000,
      "itemId": null
    }
  ]
}
```

### `GET /receipts` · `POST /receipts`

**POST payload**

```json
{
  "customerId": "uuid",
  "date": "2026-10-09",
  "cashMinor": 450000,
  "whtMinor": 50000,
  "whtCertificate": "WHT-2026-001",
  "cashAccount": "1000",
  "bankRef": "TRX-99",
  "memo": null,
  "allocations": [
    { "invoiceId": "uuid", "amountMinor": 500000 }
  ]
}
```

- `cashMinor` + `whtMinor` is the total applied when allocations are sent.
- WHT without certificate is rejected when `whtMinor` > 0.

### `POST /receipts/{id}/apply`

**Headers:** `Idempotency-Key`

```json
{
  "allocations": [
    { "invoiceId": "uuid", "amountMinor": 100000 }
  ]
}
```

Cannot allocate more than receipt available or invoice open.

### `POST /receipts/{id}/refunds`

**Headers:** `Idempotency-Key`

```json
{
  "amountMinor": 25000,
  "refundDate": "2026-10-11",
  "memo": "Overpayment refund"
}
```

---

## 7. Payables

### `GET /bills`

Query: `supplierId`, `status`, `from`, `to`, `cutoff`, `limit`, `offset`.  
Returns `{ items, total }` with `openMinor` via `bill_open()`.

### `GET /bills/{id}` · *(session)*

Optional query: `cutoff` (`YYYY-MM-DD`) for open balance.

**Expected 200**

```json
{
  "id": "uuid",
  "billNo": "BILL-2026-00012",
  "supplierId": "uuid",
  "supplierName": "Acme Supplies",
  "supplierRef": "SUP-INV-778",
  "purchaseOrderId": "uuid-or-null",
  "billDate": "2026-10-09",
  "dueDate": "2026-11-08",
  "currency": "GHS",
  "netMinor": 100000,
  "taxMinor": 15000,
  "grossMinor": 115000,
  "openMinor": 115000,
  "expenseAccountId": "uuid",
  "status": "posted",
  "journalId": "uuid",
  "memo": null,
  "createdBy": "uuid",
  "lines": [
    {
      "id": "uuid",
      "lineNo": 1,
      "purchaseOrderLineId": "uuid-or-null",
      "itemId": "uuid-or-null",
      "description": "Raw material X",
      "qty": "100",
      "unit": "pcs",
      "unitPriceMinor": 800,
      "netMinor": 80000,
      "taxMinor": 0,
      "grossMinor": 80000
    }
  ],
  "createdAt": "2026-10-09T12:00:00+00:00",
  "updatedAt": "2026-10-09T12:00:00+00:00"
}
```

`status`: `posted` \| `void` \| `mismatch`.

### `POST /bills`

**Headers:** `Idempotency-Key`

Header totals drive the journal. Optional `lines` for matching (including `purchaseOrderLineId`).

```json
{
  "supplierId": "uuid",
  "billDate": "2026-10-09",
  "dueDate": "2026-11-08",
  "supplierRef": "SUP-INV-778",
  "currency": "GHS",
  "netMinor": 100000,
  "taxMinor": 15000,
  "grossMinor": 115000,
  "expenseAccountId": null,
  "expenseAccountCode": "5000",
  "memo": null,
  "purchaseOrderId": "uuid-or-null",
  "status": "posted",
  "lines": [
    {
      "purchaseOrderLineId": "uuid-or-null",
      "itemId": "uuid-or-null",
      "description": "Raw material X",
      "qty": 100,
      "unit": "pcs",
      "unitPriceMinor": 800,
      "netMinor": 80000,
      "taxMinor": 0,
      "grossMinor": 80000
    }
  ]
}
```

- `status` optional: `posted` (default) \| `mismatch`
- If `purchaseOrderLineId` is set, line must belong to this supplier (and to `purchaseOrderId` when that is set)
- Duplicate `supplierRef` for same supplier → `409`

### `POST /bills/{id}/resolve` · *(session)*

**Headers:** `Idempotency-Key`

Clears a **mismatch** hold → `posted`. Does **not** post a second journal or move stock.

```json
{
  "note": "Price variance accepted within tolerance"
}
```

`note` optional. Bill must be `status: "mismatch"` else `422`.

**Expected 200**

```json
{
  "id": "uuid",
  "billNo": "BILL-2026-00012",
  "status": "posted"
}
```

### `GET /supplier-payments` · `POST /supplier-payments`

**POST** = prepare (not yet posted to bank).

```json
{
  "supplierId": "uuid",
  "paymentDate": "2026-10-09",
  "amountMinor": 25000,
  "currency": "GHS",
  "bankAccountId": null,
  "reference": "PAY-100",
  "allocations": [
    { "billId": "uuid", "amountMinor": 25000 }
  ]
}
```

### `POST /supplier-payments/{id}/confirm`

**Headers:** `Idempotency-Key`  
No body. **SoD:** confirmer must ≠ preparer → otherwise `403` / `sod_violation`.

### `POST /supplier-payments/{id}/cancel`

**Headers:** `Idempotency-Key`  
Cancel a prepared (not confirmed) payment.

---

## 8. Inventory

### `GET /stock/on-hand`

Query: `itemId`, `locationId`.

**Expected 200** — array of:

```json
{
  "itemId": "uuid",
  "locationId": "uuid",
  "qtyOnHand": 100,
  "qtyAvailable": 80
}
```

### `GET /stock/assignments` · `POST /stock/assignments`

**POST** — one lot per request. Permission: `stock.move`.

**Headers:** `Idempotency-Key`

```json
{
  "itemId": "uuid",
  "locationId": "uuid",
  "lot": "OB-4001",
  "qty": 10,
  "salesOrderLineId": null,
  "memo": null
}
```

### `POST /stock/assignments/bulk` · *(2026-10-27)*

**Headers:** `Idempotency-Key`  
Permission: `stock.move`. All-or-nothing (one failure rolls the batch back). Max **100** lines.

Header fields are defaults; a line can override `itemId`, `locationId`, or `salesOrderLineId`.

```json
{
  "itemId": "uuid",
  "locationId": "uuid",
  "salesOrderLineId": "uuid-or-null",
  "memo": "Assign for SO-2026-0109",
  "lines": [
    { "lot": "OB-4001", "qty": 5 },
    { "lot": "OB-4002", "qty": 3 }
  ]
}
```

**Expected 201**

```json
{
  "items": [ { "id": "uuid", "itemId": "uuid", "lot": "OB-4001", "qty": "5.000", "status": "pending" } ],
  "total": 2
}
```

### `POST /stock/assignments/{id}/decide`

**Headers:** `Idempotency-Key`

```json
{
  "decision": "approve",
  "memo": null
}
```

`decision`: `approve` \| `reject`. SoD: decider ≠ requester.

### `POST /deliveries`

**Headers:** `Idempotency-Key`

```json
{
  "deliveryDate": "2026-10-09",
  "salesOrderId": "uuid-or-null",
  "customerId": "uuid-or-null",
  "memo": null,
  "lines": [
    {
      "itemId": "uuid",
      "locationId": "uuid",
      "qty": 5,
      "assignmentId": null
    }
  ]
}
```

Posts stock issue + COGS journal. Insufficient stock → `409`.

<!--
  ============================================================================
  NEW PAYLOADS (2026-10-13) — Inventory extensions for frontend-critical screens
  Added: stock movements/ledger, transfers, counts, traceability, order tracking.
  These match review-build routes: inventory/ledger, inventory/transfers,
  inventory/counts, inventory/trace, tracking.
  ============================================================================
-->

### `GET /stock/movements` · `GET /stock/ledger` · *(new)*

Same response for both paths (`/stock/ledger` is the UI-facing alias).

**Query (all optional):** `itemId`, `locationId`, `lot`, `movementType`, `sourceType`, `sourceId`, `from`, `to`, `q`, `limit`, `offset`.

`movementType`: `receipt` \| `issue` \| `transfer_in` \| `transfer_out` \| `adjustment` \| `delivery` \| `opening` \| `return`.

**Expected 200**

```json
{
  "items": [
    {
      "id": 1,
      "movementType": "transfer_out",
      "itemId": "uuid",
      "sku": "TR-4001",
      "itemName": "Widget",
      "locationId": "uuid",
      "locationCode": "TEM-GEN",
      "lot": "OB-4001",
      "qty": -20,
      "unitCostMinor": 1500,
      "movementDate": "2026-10-13",
      "sourceType": "stock_transfer",
      "sourceId": "uuid",
      "memo": null
    }
  ],
  "total": 1
}
```

### `GET /stock/traceability/{lot}` · *(new)*

No body. Example: `GET /stock/traceability/OB-4001`.

**Expected 200** — lot on-hand by location, chronological movements, and source chain.

```json
{
  "lot": "OB-4001",
  "onHand": [
    { "locationId": "uuid", "locationCode": "TEM-GEN", "qtyOnHand": 80 }
  ],
  "movements": [],
  "chain": [
    { "sourceType": "goods_receipt", "sourceId": "uuid", "movementType": "receipt", "date": "2026-09-01" }
  ]
}
```

### `GET /stock/transfers` · *(new)*

**Query (optional):** `itemId`, `fromLocationId`, `toLocationId`, `status`, `limit`, `offset`.

### `POST /stock/transfers` · *(new)*

**Headers:** `Idempotency-Key`

Moves stock between locations in one step (`status: received`). Writes paired `transfer_out` + `transfer_in` movements.

```json
{
  "itemId": "uuid",
  "fromLocationId": "uuid",
  "toLocationId": "uuid",
  "lot": "OB-4001",
  "qty": 20,
  "transferDate": "2026-10-13",
  "memo": "Move to Kumasi store"
}
```

| Field | Required | Notes |
|--------|----------|--------|
| `itemId` | yes | uuid |
| `fromLocationId` | yes | uuid |
| `toLocationId` | yes | uuid; must differ from `fromLocationId` |
| `lot` | no | string |
| `qty` | yes | positive number |
| `transferDate` | yes | `YYYY-MM-DD` |
| `memo` | no | |

**Expected 201**

### `POST /stock/transfers/bulk` · *(2026-10-27)*

**Headers:** `Idempotency-Key`  
Permission: `stock.move`. All-or-nothing. Max **100** lines.

Header fields are defaults; a line can override `fromLocationId`, `toLocationId`, `transferDate`, or `memo`.

```json
{
  "fromLocationId": "uuid",
  "toLocationId": "uuid",
  "transferDate": "2026-10-27",
  "memo": "Move several lots to Kumasi",
  "lines": [
    { "itemId": "uuid", "lot": "OB-4001", "qty": 20 },
    { "itemId": "uuid", "lot": "OB-4002", "qty": 8 }
  ]
}
```

| Field | Required | Notes |
|--------|----------|--------|
| `lines` | yes | 1–100 objects |
| `lines[].itemId` | yes | uuid |
| `lines[].qty` | yes | positive number |
| `lines[].lot` | no | |
| `fromLocationId` / `toLocationId` | yes on header or each line | must differ |
| `transferDate` | yes on header or each line | `YYYY-MM-DD` |

**Expected 201**

```json
{
  "items": [ { "id": "uuid", "transferNo": "TR-2026-00001", "lot": "OB-4001", "qty": 20, "status": "received" } ],
  "total": 2
}
```

### `GET /stock/counts` · `GET /stock/counts/{id}` · *(new)*

**List query (optional):** `status`, `locationId`, `limit`, `offset`.

`status`: `draft` \| `awaiting_supervisor` \| `posted` \| `rejected`.

### `POST /stock/counts` · *(new)*

**Headers:** `Idempotency-Key`

Creates a **draft** cycle-count session. If `bookQty` is omitted on a line, server snapshots current `stock_on_hand`.

```json
{
  "locationId": "uuid",
  "countDate": "2026-10-13",
  "memo": "Month-end Tema count",
  "lines": [
    {
      "itemId": "uuid",
      "lot": "OB-4001",
      "bookQty": 100,
      "countedQty": 100,
      "reason": null
    },
    {
      "itemId": "uuid",
      "lot": "OB-4005",
      "countedQty": 588,
      "reason": "Breakage in bay C not recorded"
    }
  ]
}
```

| Field | Required | Notes |
|--------|----------|--------|
| `locationId` | yes | uuid |
| `countDate` | yes | `YYYY-MM-DD` |
| `memo` | no | |
| `lines` | yes | min 1 |
| `lines[].itemId` | yes | uuid |
| `lines[].lot` | no | |
| `lines[].bookQty` | no | defaults from on-hand |
| `lines[].countedQty` | yes | number |
| `lines[].reason` | no | required on approve if variance is negative |

**Expected 201** — `status: draft`.

### `POST /stock/counts/{id}/submit` · *(new)*

**Headers:** `Idempotency-Key`  
No body (or `{}`). Stock keeper submits → `awaiting_supervisor`.

### `POST /stock/counts/{id}/approve` · *(new)*

**Headers:** `Idempotency-Key`  
Permission: `stock.approve`. **SoD:** approver must ≠ submitter.

Approve (posts adjustment movements for non-zero variances):

```json
{}
```

or

```json
{ "reject": false }
```

Reject (no stock movements):

```json
{ "reject": true }
```

### `GET /tracking` · *(new)*

Order tracking search (frontend **Order tracking** screen).

**Query (required):** `q` — sales order no, PO no, quotation no, invoice no, GRN no, lot, or id.

```http
GET /api/v1/tracking?q=SO-2026
GET /api/v1/tracking?q=OB-4001
```

**Expected 200**

```json
{
  "query": "SO-2026",
  "matches": [
    {
      "kind": "sales_order",
      "id": "uuid",
      "ref": "SO-2026-00012",
      "status": "confirmed",
      "date": "2026-10-01",
      "path": "/sales-orders/uuid"
    }
  ]
}
```

`kind`: `sales_order` \| `purchase_order` \| `quotation` \| `invoice` \| `goods_receipt` \| `lot`.

---

## 9. Sales

### `GET /quotations` · `GET /quotations/{id}` · `POST /quotations`

**POST**

```json
{
  "customerId": "uuid",
  "quotationDate": "2026-10-09",
  "validUntil": "2026-10-23",
  "currency": "GHS",
  "memo": null,
  "discountPercent": 30,
  "lines": [
    {
      "description": "Widget A",
      "qty": 10,
      "unitPriceMinor": 15000,
      "itemId": "uuid-or-null",
      "unit": "ea"
    }
  ]
}
```

`discountPercent` is optional (`0`–`100`). Applied to the pre-tax total (e.g. 1000 − 30% = 700).

### `PUT /quotations/{id}`

**Headers:** `Idempotency-Key`  
Editable while status is `draft` or `sent`.

```json
{
  "validUntil": "2026-10-30",
  "currency": "GHS",
  "memo": "Revised",
  "discountPercent": 15,
  "lines": [
    {
      "description": "Widget A",
      "qty": 12,
      "unitPriceMinor": 14000,
      "itemId": "uuid-or-null",
      "unit": "ea"
    }
  ]
}
```

All fields optional. Send `lines` only when replacing them.

### `POST /quotations/{id}/status`

**Headers:** `Idempotency-Key`

```json
{ "status": "sent" }
```

`status`: `sent` \| `accepted` \| `declined` \| `expired`.

### `POST /quotations/{id}/convert`

**Headers:** `Idempotency-Key`

```json
{
  "orderDate": "2026-10-09",
  "requiredDate": null,
  "customerPoRef": "PO-55",
  "deliverTo": null
}
```

Creates a sales order from the quotation. **Expected 201**.

### `GET /sales-orders` · `GET /sales-orders/{id}` · `POST /sales-orders`

**POST**

```json
{
  "customerId": "uuid",
  "orderDate": "2026-10-09",
  "requiredDate": "2026-10-20",
  "customerPoRef": "PO-55",
  "orderTypeId": null,
  "quotationId": null,
  "currency": "GHS",
  "memo": null,
  "deliverTo": "Site B",
  "lines": [
    {
      "description": "Widget A",
      "qty": 10,
      "unitPriceMinor": 15000,
      "itemId": "uuid",
      "unit": "ea"
    }
  ]
}
```

### `POST /sales-orders/{id}/confirm`

**Headers:** `Idempotency-Key`  
No body.

---

## 10. Procurement

> Endpoints marked ***(session)*** were added in the Procurement API coverage session (Phases 1–4).  
> Deploy note for Phase 4 schema: migrate `2026_10_13_230000_phase4_procurement_extensions.php`.

### `GET /purchase-orders`

Query: `supplierId`, `status`, `from`, `to`, `q`, `limit`, `offset`.  
Returns `{ data, total }` with lines and `qtyReceived`.

### `GET /purchase-orders/{id}` · *(session)*

**Expected 200**

```json
{
  "id": "uuid",
  "poNo": "PO-2026-00042",
  "supplierId": "uuid",
  "supplierName": "Acme Supplies",
  "orderDate": "2026-10-09",
  "expectedDate": "2026-10-25",
  "status": "approved",
  "currency": "GHS",
  "fxRate": null,
  "netMinor": 80000,
  "taxTotalMinor": 0,
  "grossMinor": 80000,
  "memo": null,
  "createdBy": "uuid",
  "approvedBy": "uuid",
  "approvedAt": "2026-10-09T14:00:00+00:00",
  "lines": [
    {
      "id": "uuid",
      "lineNo": 1,
      "itemId": "uuid",
      "salesOrderLineId": null,
      "description": "Raw material X",
      "qty": "100",
      "unit": "pcs",
      "unitPriceMinor": 800,
      "netMinor": 80000,
      "taxMinor": 0,
      "grossMinor": 80000,
      "qtyReceived": "40",
      "qtyOpen": "60",
      "lineCategory": "stocking"
    }
  ],
  "createdAt": "2026-10-09T12:00:00+00:00",
  "updatedAt": "2026-10-09T14:00:00+00:00"
}
```

`status`: `draft` \| `approved` \| `cancelled` \| `closed`.

### `POST /purchase-orders`

**Headers:** `Idempotency-Key`

```json
{
  "supplierId": "uuid",
  "orderDate": "2026-10-09",
  "expectedDate": "2026-10-25",
  "currency": "GHS",
  "memo": null,
  "lines": [
    {
      "description": "Raw material X",
      "qty": 100,
      "unitPriceMinor": 800,
      "itemId": "uuid-or-null",
      "unit": "pcs",
      "taxMinor": 0,
      "salesOrderLineId": null,
      "lineCategory": "stocking"
    }
  ]
}
```

`lineCategory`: `stocking` \| `direct` \| `import`.

### `POST /purchase-orders/{id}/approve`

**Headers:** `Idempotency-Key`  
No body. SoD: approver ≠ creator. Only `draft` → `approved`.

### `POST /purchase-orders/{id}/cancel` · *(session)*

**Headers:** `Idempotency-Key`  
No body. Permission: `po.cancel`.

- Allowed: `draft`, or `approved` with **zero** received qty on every line  
- Blocked if any `qtyReceived > 0` (close residual instead)  
- No stock / journal side effects  

**Expected 200**

```json
{
  "id": "uuid",
  "poNo": "PO-2026-00042",
  "status": "cancelled",
  "previousStatus": "draft"
}
```

### `POST /purchase-orders/{id}/close` · *(session)*

**Headers:** `Idempotency-Key`  
No body. Permission: `po.close`.

- Only `approved` → `closed` (partial residual open qty allowed)  
- No stock / journal side effects  

**Expected 200**

```json
{
  "id": "uuid",
  "poNo": "PO-2026-00042",
  "status": "closed",
  "previousStatus": "approved",
  "qtyOrdered": "100",
  "qtyReceived": "40"
}
```

### `GET /purchase-orders/{id}/pdf` · *(session)*

Returns `application/pdf` (inline). Permission: `po.view`.  
Header `X-Document-File-Id` = stored `document_file` id.  
Placeholder PDF until document templates are fully wired.

### `GET /documents/purchase-orders/{id}` · *(session)*

Canonical documents path (same PDF as above). Prefer this for new clients; `{id}/pdf` is an alias.

### `GET /goods-receipts` · *(session)*

Query: `purchaseOrderId`, `status`, `from`, `to`, `locationId`, `q`, `limit`, `offset`.

**Expected 200**

```json
{
  "data": [
    {
      "id": "uuid",
      "grnNo": "GRN-2026-00008",
      "purchaseOrderId": "uuid",
      "poNo": "PO-2026-00042",
      "locationId": "uuid",
      "receiptDate": "2026-10-10",
      "status": "received",
      "journalId": "uuid",
      "memo": null,
      "lineCount": 2,
      "createdAt": "2026-10-10T09:00:00+00:00"
    }
  ],
  "total": 1
}
```

### `GET /goods-receipts/{id}` · *(session)*

**Expected 200**

```json
{
  "id": "uuid",
  "grnNo": "GRN-2026-00008",
  "purchaseOrderId": "uuid",
  "poNo": "PO-2026-00042",
  "supplierId": "uuid",
  "locationId": "uuid",
  "locationCode": "WH-01",
  "locationName": "Main warehouse",
  "receiptDate": "2026-10-10",
  "status": "received",
  "journalId": "uuid",
  "memo": null,
  "createdBy": "uuid",
  "lines": [
    {
      "id": "uuid",
      "lineNo": 1,
      "purchaseOrderLineId": "uuid",
      "itemId": "uuid",
      "qtyReceived": "40",
      "qtyCertified": "0",
      "qtyQuarantine": "0",
      "qtyRejected": "0",
      "lot": null,
      "unitCostMinor": 800,
      "qcStatus": "pending",
      "storeLocationId": null,
      "stockMovementId": null,
      "qcNote": null,
      "qcBy": null,
      "qcAt": null
    }
  ],
  "createdAt": "2026-10-10T09:00:00+00:00",
  "updatedAt": "2026-10-10T09:00:00+00:00"
}
```

### `POST /goods-receipts`

**Headers:** `Idempotency-Key`

```json
{
  "purchaseOrderId": "uuid",
  "receiptDate": "2026-10-09",
  "locationId": "uuid",
  "memo": null,
  "lines": [
    {
      "purchaseOrderLineId": "uuid",
      "qty": 100,
      "itemId": "uuid-or-null"
    }
  ]
}
```

Posts stock + GRNI journal. PO must be `approved`.

### `GET /goods-receipts/pending-qc`

List receipt lines waiting QC. Query: `goodsReceiptId`, `itemId`, `limit`, `offset`.

### `POST /qc/{id}`

**Headers:** `Idempotency-Key`  
Path `{id}` = **goods receipt line** id.

```json
{
  "result": "pass",
  "memo": null,
  "lines": [
    {
      "goodsReceiptLineId": "uuid",
      "acceptedQty": 100,
      "rejectedQty": 0
    }
  ]
}
```

`result`: `pass` \| `fail` \| `partial`.

---

### Requisitions · *(session)*

#### `GET /requisitions`

Query: `status`, `limit`, `offset`. Returns `{ data, total }`.

Statuses: `draft` \| `pending` \| `approved` \| `rejected` \| `converted`.

#### `POST /requisitions`

**Headers:** `Idempotency-Key`

```json
{
  "requestDate": "2026-10-09",
  "preferredSupplierId": "uuid-or-null",
  "memo": "For SO-2026-0101",
  "submit": true,
  "lines": [
    {
      "itemId": "uuid-or-null",
      "description": "Solar lantern",
      "qty": 50,
      "unit": "pcs",
      "estimatedUnitPriceMinor": 3800
    }
  ]
}
```

- `submit: true` (default) → status `pending`  
- `submit: false` → status `draft`

**Expected 201** — same shape as list item (`id`, `reqNo`, `status`, `lines`, …).

#### `POST /requisitions/{id}/approve`

**Headers:** `Idempotency-Key`  
No body. Permission: `po.approve`. SoD: approver ≠ requester. Only `pending`/`draft`.

#### `POST /requisitions/{id}/reject`

**Headers:** `Idempotency-Key`

```json
{
  "reason": "Duplicate of REQ-2026-00003"
}
```

`reason` optional.

#### `POST /requisitions/{id}/convert`

**Headers:** `Idempotency-Key`

Creates a **draft** purchase order via the existing PO create path (no second creator).

```json
{
  "supplierId": "uuid",
  "orderDate": "2026-10-09"
}
```

- `supplierId` required if requisition has no `preferredSupplierId`  
- Already converted → `409`  
- Only `approved` can convert  

**Expected 200**

```json
{
  "id": "uuid",
  "reqNo": "REQ-2026-00001",
  "status": "converted",
  "purchaseOrderId": "uuid",
  "poNo": "PO-2026-00050"
}
```

---

### RFQs · *(session)*

#### `GET /rfqs`

Query: `status`, `limit`, `offset`. Returns `{ data, total }` with lines and bids.

Statuses: `open` \| `closed` \| `awarded` \| `cancelled`.

#### `POST /rfqs`

**Headers:** `Idempotency-Key`

```json
{
  "issueDate": "2026-10-09",
  "dueDate": "2026-10-20",
  "memo": null,
  "lines": [
    {
      "itemId": "uuid-or-null",
      "description": "Solar lantern",
      "qty": 300,
      "unit": "pcs"
    }
  ]
}
```

**Expected 201** — RFQ with `status: "open"`.

#### `POST /rfqs/{id}/bids`

**Headers:** `Idempotency-Key`

```json
{
  "supplierId": "uuid",
  "bidDate": "2026-10-12",
  "currency": "GHS",
  "totalMinor": 1140000,
  "memo": null,
  "linePrices": [
    { "rfqLineId": "uuid", "unitPriceMinor": 3800 }
  ]
}
```

- RFQ must be `open`  
- One bid per supplier per RFQ (duplicate → `409`)  
- `totalMinor` must be positive  

#### `POST /rfqs/{id}/award`

**Headers:** `Idempotency-Key`

Creates a **draft** PO via existing `CreatePurchaseOrder` (single PO path). Other submitted bids → `rejected`.

```json
{
  "bidId": "uuid",
  "orderDate": "2026-10-13"
}
```

**Expected 200**

```json
{
  "id": "uuid",
  "rfqNo": "RFQ-2026-00001",
  "status": "awarded",
  "awardedBidId": "uuid",
  "purchaseOrderId": "uuid",
  "poNo": "PO-2026-00051"
}
```

Already awarded → `409`.

---

### Not implemented (by design this session)

| Requested | Reason |
|-----------|--------|
| `POST /purchase-orders/{id}/progress` | Progress is derived from GRN `qtyReceived`; write semantics need product decision |
| `GET /purchase-orders/{id}/progress` | Use `GET /purchase-orders/{id}` (`qty` / `qtyReceived` / `qtyOpen`) |
| `POST /purchase-orders/{id}/acknowledge` | No acknowledge status/field in domain yet; needs product decision |

---

## 11. Payroll

### `GET /employees` · `POST /employees`

**POST**

```json
{
  "displayName": "Ama Boateng",
  "employeeNumber": "E-104",
  "hireDate": "2026-01-15",
  "jobTitle": "Accountant",
  "basicSalaryMinor": 450000
}
```

### `GET /payroll/runs` · `GET /payroll/runs/{id}` · `POST /payroll/runs`

**POST**

```json
{
  "periodYear": 2026,
  "periodMonth": 10,
  "memo": null
}
```

### Lifecycle (all need `Idempotency-Key`, no body)

| Method | Path | Effect |
|--------|------|--------|
| POST | `/payroll/runs/{id}/approve` | Approve |
| POST | `/payroll/runs/{id}/post` | Post journals |
| POST | `/payroll/runs/{id}/pay` | Mark paid |

Order: create → approve → post → pay.

---

## 12. Assets

### `GET /assets` · `GET /asset-classes` · `POST /assets`

**POST**

```json
{
  "name": "Laptop – Finance",
  "assetClassId": "uuid",
  "acquisitionDate": "2026-03-01",
  "costMinor": 850000,
  "residualValueMinor": 50000,
  "usefulLifeMonths": 36,
  "locationId": null
}
```

### `POST /assets/depreciation/{month}`

Path `{month}` = `YYYY-MM` (e.g. `2026-10`).  
**Headers:** `Idempotency-Key`  
No body. Posts depreciation for that month.

---

## 13. Treasury

### `GET /expenses` · `POST /expenses`

**POST**

```json
{
  "expenseDate": "2026-10-09",
  "amountMinor": 15000,
  "accountId": "uuid-expense-account",
  "bankAccountId": null,
  "memo": "Taxi",
  "partyId": null
}
```

### `POST /transfers`

**Headers:** `Idempotency-Key`

```json
{
  "transferDate": "2026-10-09",
  "amountMinor": 1000000,
  "fromAccountId": "uuid-bank-a",
  "toAccountId": "uuid-bank-b",
  "memo": "Top-up"
}
```

---

## 14. Quick checklist for frontend forms

1. On form open → generate **Idempotency-Key** (UUID); keep it for all submits of that form session.
2. Money inputs → convert to integer minor units before send.
3. Dates → `YYYY-MM-DD` strings.
4. On `422` → read `fields` and highlight inputs.
5. On `409` with `period_locked` / `stale_revision` / `sod_violation` → show `message`; for stale, reload and retry.
6. On success `201`/`200` → use returned body (ids, numbers, `rowVersion`) for the next screen.
7. Never send `companyId`.
8. Commands that are pure actions (approve, confirm, post, pay) often have **empty body** but still need **Idempotency-Key**.

---

## 15. Example: post invoice end-to-end

```http
POST /api/v1/invoices HTTP/1.1
Content-Type: application/json
Idempotency-Key: 7c9e6679-7425-40de-944b-e07fc1f90ae7
x-dev-user: admin@example.com

{
  "customerId": "a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11",
  "invoiceDate": "2026-10-09",
  "dueDate": "2026-11-08",
  "currency": "GHS",
  "memo": null,
  "salesOrderId": null,
  "lines": [
    {
      "description": "Monthly retainer",
      "qty": 1,
      "unitPriceMinor": 1200000,
      "itemId": null,
      "unit": null
    }
  ]
}
```

**Expected 201** (shape illustrative):

```json
{
  "id": "…",
  "invoiceNumber": "INV-2026-0142",
  "customerId": "a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11",
  "invoiceDate": "2026-10-09",
  "dueDate": "2026-11-08",
  "currency": "GHS",
  "status": "posted",
  "totalMinor": 1200000,
  "openMinor": 1200000,
  "lines": [ … ]
}
```

If the same request is retried with the **same** Idempotency-Key, you get the same body again and `Idempotent-Replay: true` (no second invoice).

---

## 16. Step 15 APIs

New endpoints from Step 15 (quotations edit, alerts, FX, imports, delivery, logistics).  
Jobs screen is intentionally excluded.  
Commands need `Idempotency-Key`. Money is minor units.

### 16.1 Alerts

#### `GET /stock/low-stock`

No body. Permission: `stock.view`.  
Items at or below **50%** of reorder point.

**Expected 200**

```json
{
  "items": [
    {
      "itemId": "uuid",
      "sku": "WDG-01",
      "name": "Widget A",
      "onHand": "4",
      "reorderPoint": "10",
      "threshold": "5"
    }
  ],
  "total": 1,
  "thresholdPercent": 50
}
```

#### `GET /alerts`

No body. System alerts for the signed-in user.  
Empty for customer portal (`userType=portal`).  
Also included on `GET /me` for staff with `stock.view`.

---

### 16.2 FX

#### `POST /fx/rates/fetch-xe`

**Headers:** `Idempotency-Key`

```json
{
  "date": "2026-10-15",
  "currencies": ["USD", "EUR", "GBP"]
}
```

Both fields optional. Stores mid rate; **sell = mid × 1.03**.  
Optional env: `XE_ACCOUNT_ID`, `XE_API_KEY`.

---

### 16.3 Imports

#### `GET /imports/templates`

No body. Lists import types and columns.

#### `GET /imports/templates/{type}`

No body. Returns the CSV template for `{type}`.

`{type}`: `items` | `customers` | `suppliers` | `accounts` | `receivables` | `payables` | `delivery` | `bank-statement`

#### `POST /imports/{type}`

**Headers:** `Idempotency-Key`  
Body: multipart file (CSV matching the template), or JSON rows.

All-or-nothing: any invalid row rejects the whole file with row/column reason.

---

### 16.4 Delivery notes

Status: `planned` → `picking` → `packed` → `dispatched` → `delivered` | `failed`.  
Stock + COGS journal only on **confirm**.

#### `GET /delivery-notes` · `GET /delivery-notes/{id}`

No body.

#### `POST /delivery-notes`

**Headers:** `Idempotency-Key`

```json
{
  "salesOrderId": "uuid",
  "plannedDate": "2026-10-16",
  "assignmentIds": ["uuid"],
  "route": "Accra North",
  "memo": null
}
```

#### `POST /delivery-notes/{id}/pick`

```json
{ "pickedBy": "Kofi" }
```

#### `POST /delivery-notes/{id}/pack`

```json
{
  "packages": "2 cartons",
  "weightKg": 12.5,
  "volumeM3": 0.08,
  "packedBy": "Ama"
}
```

#### `POST /delivery-notes/{id}/dispatch`

```json
{
  "vehicle": "GT-1234-20",
  "driver": "Yaw",
  "carrier": "DOS Logistics",
  "route": "Accra North"
}
```

#### `POST /delivery-notes/{id}/confirm`

```json
{
  "deliveredDate": "2026-10-16",
  "receiver": "Site store",
  "podReference": "POD-88",
  "note": null
}
```

#### `POST /delivery-notes/{id}/fail`

```json
{
  "reason": "Customer closed",
  "note": null
}
```

#### `POST /delivery-notes/{id}/reschedule`

```json
{ "plannedDate": "2026-10-18" }
```

---

### 16.5 Logistics (jobs excluded)

One shipment can group lines from many purchase orders (bulk / same container).  
Landed costs are **entered**, then allocated by qty / weight / volume.  
Permissions: `logistics.view`, `logistics.manage`, `logistics.customs`, `logistics.costs`.

#### `GET /shipments` · `GET /shipments/{id}`

No body.

#### `GET /shipments/{id}/landed-cost`

No body. Worksheet with entered costs and allocation.

#### `POST /shipments`

**Headers:** `Idempotency-Key`

```json
{
  "kind": "inbound",
  "transport": "sea",
  "mode": "FCL",
  "booking": "BK-1001",
  "incoterm": "CIF Tema",
  "shipperId": "uuid",
  "consignee": "DOS Limited",
  "carrierId": "uuid",
  "forwarderId": "uuid",
  "origin": "Shanghai",
  "note": null,
  "consignments": [
    {
      "purchaseOrderLineId": "uuid",
      "itemId": "uuid",
      "qty": 100,
      "container": "MSCU1234567",
      "seal": "SL-9",
      "bl": "BL-55",
      "packages": 10,
      "weightKg": 800,
      "volumeM3": 4.2,
      "eta": "2026-11-02",
      "legs": [
        {
          "seq": 1,
          "from": "Shanghai",
          "to": "Tema",
          "carrierId": "uuid",
          "vessel": "MSC Example",
          "plannedDep": "2026-10-18",
          "plannedArr": "2026-11-02"
        }
      ]
    }
  ]
}
```

`consignments` required (min 1). Several lines from different POs can share one shipment / container.

#### `POST /shipments/{id}/events`

```json
{
  "eventType": "departed",
  "location": "Shanghai",
  "consignmentId": "uuid",
  "qtyAffected": 100,
  "source": "carrier"
}
```

#### `POST /shipments/{id}/consignments/{cid}/receive`

```json
{ "goodsReceiptId": "uuid" }
```

`goodsReceiptId` optional. Blocked while customs is on hold.

#### `GET /customs` · `GET /customs/{id}`

No body.

#### `POST /customs`

**Headers:** `Idempotency-Key`

```json
{
  "shipmentId": "uuid",
  "consignmentId": "uuid",
  "jurisdiction": "Ghana",
  "brokerId": "uuid",
  "hsCode": "8471.30",
  "valuation": "CIF",
  "dutiesMinor": 250000,
  "recoverableTaxMinor": 180000,
  "nonrecoverableMinor": 40000,
  "inspection": "pending",
  "freeTimeEnd": "2026-11-07",
  "demurrageMinor": 0,
  "docs": ["commercial invoice", "packing list", "BL"]
}
```

#### `POST /customs/{id}/release`

```json
{ "payment": "DUTY-REF-12" }
```

#### `POST /landed-costs`

**Headers:** `Idempotency-Key`

```json
{
  "shipmentId": "uuid",
  "consignmentId": null,
  "category": "Freight",
  "description": "Ocean freight",
  "amountMinor": 450000,
  "currency": "USD",
  "recoverable": false,
  "status": "Actual",
  "source": "invoice"
}
```

`category`: `Freight` | `Insurance` | `Duty` | `Clearance` | `Supplier purchase` | `Other`.  
`status`: `Committed` | `Actual`.

#### `GET /eta-revisions`

No body.

#### `POST /eta-revisions`

```json
{
  "shipmentId": "uuid",
  "salesOrderId": null,
  "originalPromise": "2026-11-02",
  "previous": "2026-11-02",
  "revised": "2026-11-06",
  "source": "carrier",
  "confidence": "medium",
  "reason": "Port congestion",
  "revisedBy": "Ops"
}
```

#### `GET /logistics/exceptions`

No body. Derived exceptions (customs hold, missed ETA, and similar).

---

## 15. Step 16 APIs (created or updated)

All commands require `Idempotency-Key`. Money fields are integers in minor units. Dates are `YYYY-MM-DD`. IDs are UUIDs. Company is taken from the authenticated user — do not send `companyId`.

### 16.1 Leave

#### `GET /leave/me`

Own balance + requests. Permission: `people.leave.request` (or authenticated employee).

**Response 200**

```json
{
  "balance": { "annualEntitlement": 15, "used": 3, "remaining": 12 },
  "requests": [
    {
      "id": "uuid",
      "leaveType": "Annual",
      "fromDate": "2026-11-02",
      "toDate": "2026-11-04",
      "days": 3,
      "reason": "Family",
      "status": "pending",
      "requestedAt": "2026-10-17T10:00:00Z"
    }
  ]
}
```

#### `POST /leave`

Request leave. Permission: `people.leave.request`.

```json
{
  "leaveType": "Annual",
  "fromDate": "2026-11-02",
  "toDate": "2026-11-04",
  "reason": "Family visit"
}
```

| Field | Required | Notes |
|-------|----------|-------|
| `leaveType` | no | `Annual` \| `Sick` \| `Casual` \| `Unpaid` (default Annual) |
| `fromDate` | yes | `YYYY-MM-DD` |
| `toDate` | yes | `>= fromDate` |
| `reason` | no | max 2000 |

#### `GET /leave`

HR list. Permission: `people.leave.approve`. Optional `?status=pending|approved|ongoing|expired|rejected`.

#### `POST /leave/{id}/approve`

No body. Permission: `people.leave.approve`. Approver ≠ requester.

#### `POST /leave/{id}/reject`

```json
{ "reason": "Insufficient cover that week" }
```

`reason` required, min 3 chars.

---

### 16.2 Carriers & Brokers

#### `GET /parties?type=carrier`

`type` accepts `carrier`, `broker`, or `carrier,broker`.  
Users with `logistics.view` / `logistics.manage` / `logistics.customs` can see these types.

**Response** — same party list shape as existing `GET /parties`, filtered.

Shipment and customs create endpoints now reject:
- `carrierId` that is not a carrier party
- `forwarderId` that is not broker or carrier
- leg `carrierId` that is not a carrier
- customs `brokerId` that is not a broker

---

### 16.3 Pricing calculator

#### `GET /pricing/parameters`

Returns current parameters or defaults. Permission: `sales.quotes.view`.

**Response 200**

```json
{
  "supplierCur": "USD",
  "dhlCur": "USD",
  "volDivisor": 5000,
  "dhlMin": 0,
  "dutyBasis": "pct",
  "dutyPct": 10,
  "dutyAmt": 0,
  "insuranceBasis": "pct",
  "insurancePct": 0.5,
  "clearanceBasis": "pct",
  "clearancePct": 2,
  "markupBasis": "pct",
  "markupPct": 35,
  "contingencyBasis": "pct",
  "contingencyPct": 5,
  "extraCosts": [],
  "dhlBands": [{ "upTo": 0.5, "rate": 8.5 }]
}
```

#### `PUT /pricing/parameters`

Permission: `sales.quotes.manage`. Same shape as the GET response (all fields optional/nullable).  
`extraCosts.*.name` + `amount` required when present; `per` = `unit` \| `order`.  
`dhlBands.*.rate` required and `> 0`; bands must increase.

#### `POST /pricing/calculate`

Pure calculation. Does not write. Permission: `sales.quotes.view`.

```json
{
  "itemId": "uuid",
  "sku": "SKU-001",
  "qty": 10,
  "estimate": false,
  "asOfDate": "2026-10-17",
  "buyPriceMinor": 250000
}
```

| Field | Required | Notes |
|-------|----------|-------|
| `itemId` or `sku` | one | item lookup |
| `qty` | no | default 1 |
| `estimate` | no | boolean |
| `asOfDate` | no | FX rate date |
| `buyPriceMinor` | no | override buy price (minor units) |

**Response 200**

```json
{
  "unitPriceMinor": 412500,
  "breakdown": {
    "buyPriceMinor": 250000,
    "dutyMinor": 25000,
    "insuranceMinor": 1250,
    "clearanceMinor": 5000,
    "dhlMinor": 18000,
    "markupMinor": 104587,
    "contingencyMinor": 20166,
    "extrasMinor": 0
  }
}
```

---

### 16.4 Quality — Nonconformances & Corrective actions

#### `GET /quality/nonconformances`

Optional `?status=open|sent|closed`. Permission: `quality.view`.

#### `GET /quality/corrective-actions`

Permission: `quality.view`.

#### `POST /quality/nonconformances`

```json
{
  "issue": "Wrong batch on GRN",
  "why": "Label mismatch",
  "cause": "Supplier packing error",
  "action": "Return and reorder",
  "sourceType": "goods_receipt",
  "sourceId": "uuid",
  "itemId": "uuid",
  "sku": "SKU-001",
  "qty": 5,
  "dueDate": "2026-10-24",
  "occurredAt": "2026-10-17",
  "ownerId": "uuid"
}
```

`issue` required. Others optional.

#### `POST /quality/nonconformances/{id}/send-to-corrective-action`

```json
{
  "comments": "Send to QA lead",
  "action": "Investigate supplier",
  "dueDate": "2026-10-31",
  "ownerId": "uuid",
  "verifierId": "uuid"
}
```

`comments` or `action` required.

#### `POST /quality/nonconformances/{id}/close`

Optional body `{ "note": "Resolved with supplier" }`.

#### `POST /quality/quarantine/reject`

Creates an NCR. Permission: `quality.manage`.

```json
{
  "why": "Failed QC visual",
  "when": "2026-10-17",
  "action": "Reject and return",
  "cause": "Damaged packaging",
  "sku": "SKU-001",
  "itemId": "uuid",
  "qty": 12.5,
  "lot": "LOT-441"
}
```

`why`/`issue`, `when`/`occurredAt`, `action`, `sku`/`itemId`, `qty` required.

---

### 16.5 Bank reconciliation

#### `GET /bank/lines`

Optional `?bankAccountId=uuid&status=unmatched|matched|excluded`. Permission: `bank.view`.

**Response item**

```json
{
  "id": 1042,
  "bankAccountId": "uuid",
  "date": "2026-10-15",
  "description": "CUSTOMER RCPT",
  "amountMinor": 150000,
  "status": "unmatched",
  "journalId": null
}
```

Signed amount: credit positive.

#### `GET /bank/lines/{id}/candidates`

Suggested posted journals (±15 days, same bank effect, not already matched).

#### `POST /bank/lines/{id}/match`

```json
{ "journalId": "uuid" }
```

No second posting. Unique journal match enforced. Permission: `bank.match`.

#### `POST /bank/lines/{id}/exclude`

```json
{ "reason": "Bank fee already journalled" }
```

#### `POST /bank/lines/{id}/unmatch`

No body. Reverses a match or exclusion.

#### `GET /bank/reconciliation`

Workbench. Optional `?bankAccountId=uuid&asOf=2026-10-17`.

**Response 200**

```json
{
  "bookBalanceMinor": 2500000,
  "outstandingMinor": 150000,
  "unmatchedLinesMinor": -25000,
  "adjustedBookMinor": 2625000,
  "differenceMinor": 0,
  "canPrepare": true
}
```

#### `POST /bank/reconciliations`

Prepare snapshot. Difference must be 0. Permission: `bank.match`.

```json
{
  "bankAccountId": "uuid",
  "asOf": "2026-10-17"
}
```

#### `GET /bank/reconciliations`

History.

#### `POST /bank/reconciliations/{id}/approve`

No body. Approver ≠ preparer. Permission: `bank.approve`.

---

### 16.6 Payroll run (updated)

#### `GET /payroll/runs/{id}`

Now includes employee object on each line plus calculation snapshot.

**Line shape**

```json
{
  "employeeId": "uuid",
  "employee": {
    "name": "Ama Mensah",
    "jobTitle": "Officer",
    "department": "Ops",
    "level": "junior",
    "isResident": true,
    "ssnitNumber": "SSNIT-001",
    "tin": "TIN-001",
    "bank": "GCB"
  },
  "basicMinor": 200000,
  "allowancesMinor": 20000,
  "overtimeHours": 8,
  "otTaxMinor": 600,
  "payeMinor": 15000,
  "netMinor": 185000
}
```

#### `POST /payroll/runs`

Optional `overtimeApproved` array/map of employee ids whose OT above the 24 h cap is pre-approved (no exception).

#### `POST /payroll/runs/{id}/cap-overtime`

No body. Draft only. Caps affected lines to the monthly limit, recalculates, clears exceptions. Permission: `payroll.manage`.

Approve blocks while unresolved exceptions remain.

---

### 16.7 Sales finance + Period close

#### `GET /finance/sales-summary`

Permission: `finance.view`.

**Response 200**

```json
{
  "openQuotationsValueMinor": 1250000,
  "openQuotationsCount": 4,
  "unpaidInvoicesMinor": 340000,
  "overdueMinor": 80000,
  "paidLast30DaysMinor": 2100000,
  "confirmedOrders": [
    {
      "id": "uuid",
      "number": "SO-1042",
      "customer": "Acme Ltd",
      "qtyOrdered": 20,
      "qtyDelivered": 12,
      "qtyInvoiced": 10,
      "totalMinor": 450000
    }
  ]
}
```

#### `GET /finance/sales-activity`

Optional `?from=2026-10-01&to=2026-10-31&type=sales_order&status=confirmed&limit=50&offset=0`.

Returns quotations, sales orders, invoices, credit notes, receipts.

#### `GET /close/{month}`

`{month}` = `YYYY-MM`. Permission: `ledger.read` or `period.close`.

**Response 200**

```json
{
  "month": "2026-10",
  "checks": [
    { "code": "trial_balance", "status": "Passed" },
    { "code": "unmatched_bank", "status": "Exception", "detail": "3 unmatched lines" },
    { "code": "stock_counts", "status": "Waived" }
  ],
  "openExceptions": 1,
  "canLock": false
}
```

#### `POST /close/{month}/waivers`

```json
{
  "exceptionCode": "unmatched_bank",
  "reason": "Cleared with bank; matching deferred to next period",
  "evidenceRef": "BANK-NOTE-17"
}
```

`reason` ≥ 10 chars. Permission: `period.close` or `close.manage`.

#### `POST /close/{month}/lock`

```json
{ "lockThrough": "2026-10-31" }
```

`lockThrough` optional (defaults to period end, must fall in the period). Sets `company.period_lock_date`. Blocked while open exceptions remain.

#### `POST /close/{month}/reopen`

```json
{ "reason": "Need to post late supplier invoice" }
```

`reason` ≥ 10 chars. Sets `period_lock_date` to the day before the month.

---

### 16.8 Tax rules + GL / Trial balance

#### `GET /tax/ghana`

Optional `?asOf=2026-10-17`. Returns current effective Ghana package (or 2026 defaults).  
Permission: `ledger.read`, `tax.manage`, or `payroll.approve`.

**Response 200**

```json
{
  "code": "GHANA",
  "effectiveFrom": "2026-01-01",
  "package": {
    "vat": 15,
    "nhil": 2.5,
    "getfund": 2.5,
    "paye": [
      { "upTo": 490, "rate": 0 },
      { "upTo": 110, "rate": 5 },
      { "upTo": 130, "rate": 10 },
      { "upTo": 3000, "rate": 17.5 },
      { "upTo": 16395, "rate": 25 },
      { "upTo": 29900, "rate": 30 },
      { "upTo": null, "rate": 35 }
    ],
    "ssnitEmp": 5.5,
    "ssnitEr": 13,
    "tier1": 13.5,
    "tier2": 5,
    "otJunior1": 5,
    "otJunior2": 10,
    "nonResident": 25,
    "cit": 25
  }
}
```

#### `POST /tax/ghana`

Save a new version. Accepts nested `package` **or** flat S.tax-like fields. Permission: `tax.manage`.

```json
{
  "effectiveFrom": "2026-11-01",
  "name": "Ghana 2026 Q4",
  "package": {
    "vat": 15,
    "nhil": 2.5,
    "getfund": 2.5,
    "paye": [{ "upTo": 490, "rate": 0 }],
    "ssnitEmp": 5.5,
    "ssnitEr": 13
  }
}
```

Closes any open GHANA version with an earlier `effective_from`.

#### `GET /reports/trial-balance`

Optional `?asOf=2026-10-17&currency=USD`. Permission: `ledger.read` or `reports.financial`.

**Response item**

```json
{
  "code": "1100",
  "name": "Trade receivables",
  "category": "asset",
  "fsLineItem": "Current assets",
  "fsSubLine": "Receivables",
  "normalBalance": "debit",
  "debitMinor": 340000,
  "creditMinor": 0,
  "balanceMinor": 340000
}
```

Plus `totals.balanced`.

#### `GET /reports/general-ledger`

Optional `?from=2026-10-01&to=2026-10-31&account=1100&currency=USD`.

Each section has `openingMinor`, `closingMinor`, and `entries[]` with `date`, `journalNo`, `sourceType`, `sourceId`, `description`, `memo`, `debitMinor`, `creditMinor`, running `balanceMinor`.

---

### 16.9 Calendars + report stubs

#### `GET /calendars`

Optional `?year=2026`. Returns fiscal year label, working days, shifts, holidays (sample Ghana list if none stored).

#### `PUT /calendars`

Permission: `calendars.manage`.

```json
{
  "workingDaysFactory": ["Monday","Tuesday","Wednesday","Thursday","Friday","Saturday"],
  "workingDaysOffice": ["Monday","Tuesday","Wednesday","Thursday","Friday"],
  "shifts": [
    { "name": "Day", "start": "08:00", "end": "17:00" }
  ]
}
```

#### `POST /calendars/holidays`

```json
{ "date": "2026-12-25", "name": "Christmas Day" }
```

#### `DELETE /calendars/holidays/{id}`

No body.

#### `GET /tax/filings`

Returns stored rows or the frontend sample set (VAT/WHT/PAYE/CIT).

#### `POST /tax/filings/{id}/record`

```json
{ "ref": "GRA-VAT-2026-10", "payment": "Paid" }
```

`payment` = `Not paid` \| `Paid`. Permission: `tax.manage`.

#### `GET /reports`

Report library catalogue (keys, titles, groups, paths).

#### `GET /reports/saved`

Own + shared saved reports.

#### `POST /reports/saved`

```json
{
  "name": "Monthly TB",
  "key": "trial-balance",
  "period": "2026-10",
  "currency": "GHS",
  "shared": false,
  "settings": {}
}
```

Permission: `reports.financial`.

#### `DELETE /reports/saved/{id}`

Owner only.

#### `GET /reports/favourites`

Current user’s favourite keys.

#### `POST /reports/favourites`

```json
{ "reportKey": "trial-balance" }
```

Toggles the favourite.

#### `GET /reports/stock`

Lightweight stub (valuation from stock on hand by location).

#### `GET /reports/logistics`

Transit performance stub.

#### `GET /reports/management`

Headlines / exceptions stub.

#### `GET /reports/performance?view=profit_by_product`

`view` = `profit_by_product` \| `profit_by_customer`.

#### `GET /reports/{key}`

Generic stub for any other catalogue key.

---

**Deploy note:** run `php artisan migrate` for Step 16 migrations (`2026_10_17_160100` through `2026_10_17_160900`).

---

## 17. Overview

### `GET /overview`

No body. First page after login (executive dashboard). Optional query `asOf=YYYY-MM-DD` (default today).  
Customer portal receives zeros. Money is minor units. Figures from posted journals and open documents.

**Expected 200**

```json
{
  "asOf": "2026-10-19",
  "period": { "from": "2026-10-01", "to": "2026-10-19" },
  "orders": {
    "total": 14,
    "open": 12,
    "delayed": 3,
    "awaitingApproval": 2,
    "byStage": { "draft": 2, "confirmed": 10, "closed": 2 }
  },
  "assignmentsAwaiting": 1,
  "month": {
    "revenueMinor": 4500000,
    "cogsMinor": 2800000,
    "grossProfitMinor": 1700000,
    "grossMarginPercent": 37.78
  },
  "cashMinor": 3200000,
  "stockValueMinor": 6100000,
  "receivablesOverdueMinor": 850000,
  "purchaseOrdersByStatus": { "approved": 5, "draft": 1 },
  "delivery": { "delivered": 18, "onTime": 16, "late": 2, "failed": 1 }
}
```

Account codes used: cash `1000`+`1010`+`1050`, revenue `4000`+`4100`, COGS `5000`.

---

## 18. Delivery terms, quotation send, bank accounts, quotation report

### Customers import
New columns: `delivery_address`, `delivery_terms` (EXW | FCA | FOB | CIF | DAP | DDP).  
Requires migration `2026_10_23_170000`.

### Quotation create / update
`POST /quotations` and `PUT /quotations/{id}` accept:

| Field | Required | Notes |
|-------|----------|-------|
| `deliveryLocation` | No | Free text, exact place |
| `deliveryType` | No | EXW, FCA, FAS, FOB, CFR, CIF, CPT, CIP, DAP, DPU, DDP |
| `supplierQuoteRef` | No | Supplier quote the frontend parsed |
| `lines[].itemId` | No | Omit for an item not in the catalogue |
| `lines[].toOrder` | No | Set true automatically when `itemId` is omitted |

```json
{
  "customerId": "uuid",
  "quotationDate": "2026-10-23",
  "validUntil": "2026-11-23",
  "currency": "GHS",
  "memo": "From supplier quote",
  "discountPercent": 5,
  "deliveryLocation": "Tema Port, Accra",
  "deliveryType": "DDP",
  "supplierQuoteRef": "SUP-QT-4412",
  "lines": [
    { "description": "Siemens CPU 6ES7 315", "qty": 2, "unit": "pcs", "unitPriceMinor": 1850000, "toOrder": true }
  ]
}
```

### `POST /quotations/{id}/send`

**Headers:** `Idempotency-Key`

```json
{ "to": "customer@example.com" }
```

`to` optional; uses the customer email if omitted.  
Queues a designed HTML email (letterhead, line table, totals, bank accounts) with a matching PDF attached. Marks a draft as `sent`. The worker must be running.

**Expected 200**

```json
{
  "id": "uuid",
  "quotationNo": "QT-2026-00012",
  "status": "sent",
  "sentTo": "customer@example.com",
  "documentFileId": "uuid"
}
```

### `GET /quotations/{id}/print` and `GET /invoices/{id}/print`
No body. Print payload including every active `bankAccounts[]`. Bank accounts are stored once and read at print time; they are not copied onto the document.

### `GET /bank-accounts`

**Expected 200**

```json
{ "items": [{ "id": "uuid", "bankName": "GCB", "accountName": "DOS Limited", "accountNumber": "1234567890", "branch": "Accra", "swift": null, "currency": "GHS", "isActive": true }] }
```

### `POST /bank-accounts`

**Headers:** `Idempotency-Key`

```json
{
  "bankName": "GCB",
  "accountName": "DOS Limited",
  "accountNumber": "1234567890",
  "branch": "Accra",
  "swift": "GCBGGHAC",
  "currency": "GHS",
  "isActive": true
}
```

### `GET /reports/quotations?from=2026-10-01&to=2026-10-31`

Sent vs accepted, overall and by customer. Quote the URL in Windows CMD (`&` splits the command).

```json
{
  "from": "2026-10-01",
  "to": "2026-10-31",
  "overall": { "sent": 20, "accepted": 12, "declined": 3, "expired": 1, "draft": 4, "acceptanceRate": 60.0 },
  "byCustomer": [
    { "customerId": "uuid", "customerName": "Acme", "sent": 5, "accepted": 3, "acceptanceRate": 60.0, "sentGrossMinor": 9000000, "acceptedGrossMinor": 5400000 }
  ]
}
```

### Non-stock flow
Quotation line without `itemId` (description required, `toOrder` true). Then: send → accept → convert to sales order → invoice → receipt → purchase order → bill → supplier payment → shipment → delivery. `supplierQuoteRef` holds the supplier quote reference.

---

## 19. Invoice send

### `POST /invoices/{id}/send`

**Headers:** `Idempotency-Key`  
**Permission:** `ar.invoice.post`

```json
{ "to": "customer@example.com" }
```

`to` optional; uses the customer email if omitted.  
Same designed sheet as the quotation, titled Invoice: due date, VAT / NHIL / GETFund, amount due, bank accounts. Matching PDF attached. Void invoices are rejected.

**Expected 200**

```json
{
  "id": "uuid",
  "invoiceNo": "INV-2026-00008",
  "sentTo": "customer@example.com"
}
```

The email is queued. The worker (`php artisan omnis:outbox-work`) sends it. `EMAIL_MODE=smtp` and `RESEND_API_KEY` must be set; Railway blocks SMTP ports, so the worker uses the Resend HTTPS API.
