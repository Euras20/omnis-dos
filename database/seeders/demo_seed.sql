-- OMNIS minimal demo seed (PostgreSQL)
-- Use when you prefer raw SQL over artisan (e.g. first DB bootstrap).
-- Prefer: php artisan migrate --seed
--
-- Fixed IDs match DemoSeedSeeder.php for Postman.
-- Safe to re-run: uses ON CONFLICT DO NOTHING where unique keys exist.

BEGIN;

-- 1) Company
INSERT INTO company (
  id, code, legal_name, tin, functional_currency, fiscal_year_start_month,
  timezone, period_lock_date, address, phone, email, bank_details, vat_number,
  row_version, created_at, updated_at
) VALUES (
  '11111111-1111-1111-1111-111111111111',
  'DOS',
  'DOS Limited',
  NULL,
  'GHS',
  1,
  'Africa/Accra',
  NULL,
  'Accra, Ghana',
  NULL,
  'info@dos.local',
  NULL,
  NULL,
  1,
  NOW(),
  NOW()
) ON CONFLICT (id) DO NOTHING;

-- 2) Admin role (permissions added below — full catalogue is easier via artisan seeder)
INSERT INTO role (
  id, company_id, code, name, description, approval_limit_minor,
  is_system, is_active, row_version, created_at, updated_at
) VALUES (
  '33333333-3333-3333-3333-333333333333',
  '11111111-1111-1111-1111-111111111111',
  'admin',
  'System Administrator',
  'Full access for MVP testing',
  NULL,
  TRUE,
  TRUE,
  1,
  NOW(),
  NOW()
) ON CONFLICT (id) DO NOTHING;

-- Core permissions for admin (subset enough to test; artisan seeder attaches full catalogue)
INSERT INTO role_permission (role_id, permission) VALUES
  ('33333333-3333-3333-3333-333333333333', 'admin.users'),
  ('33333333-3333-3333-3333-333333333333', 'admin.roles'),
  ('33333333-3333-3333-3333-333333333333', 'admin.templates'),
  ('33333333-3333-3333-3333-333333333333', 'customers.view'),
  ('33333333-3333-3333-3333-333333333333', 'customers.manage'),
  ('33333333-3333-3333-3333-333333333333', 'ar.invoices.view'),
  ('33333333-3333-3333-3333-333333333333', 'ar.invoice.post'),
  ('33333333-3333-3333-3333-333333333333', 'ar.receipts.view'),
  ('33333333-3333-3333-3333-333333333333', 'ar.receipt.post'),
  ('33333333-3333-3333-3333-333333333333', 'ledger.read'),
  ('33333333-3333-3333-3333-333333333333', 'ledger.journal.create'),
  ('33333333-3333-3333-3333-333333333333', 'ledger.journal.post'),
  ('33333333-3333-3333-3333-333333333333', 'ledger.accounts.manage'),
  ('33333333-3333-3333-3333-333333333333', 'po.view'),
  ('33333333-3333-3333-3333-333333333333', 'po.create'),
  ('33333333-3333-3333-3333-333333333333', 'po.approve'),
  ('33333333-3333-3333-3333-333333333333', 'suppliers.view'),
  ('33333333-3333-3333-3333-333333333333', 'suppliers.manage'),
  ('33333333-3333-3333-3333-333333333333', 'ap.bills.view'),
  ('33333333-3333-3333-3333-333333333333', 'ap.bill.post'),
  ('33333333-3333-3333-3333-333333333333', 'ap.payments.view'),
  ('33333333-3333-3333-3333-333333333333', 'ap.payment.prepare'),
  ('33333333-3333-3333-3333-333333333333', 'ap.payment.confirm'),
  ('33333333-3333-3333-3333-333333333333', 'stock.view'),
  ('33333333-3333-3333-3333-333333333333', 'stock.move'),
  ('33333333-3333-3333-3333-333333333333', 'items.view'),
  ('33333333-3333-3333-3333-333333333333', 'items.manage'),
  ('33333333-3333-3333-3333-333333333333', 'sales.quotes.view'),
  ('33333333-3333-3333-3333-333333333333', 'sales.quotes.manage'),
  ('33333333-3333-3333-3333-333333333333', 'sales.orders.view'),
  ('33333333-3333-3333-3333-333333333333', 'sales.orders.manage'),
  ('33333333-3333-3333-3333-333333333333', 'finance.view'),
  ('33333333-3333-3333-3333-333333333333', 'cash.spend'),
  ('33333333-3333-3333-3333-333333333333', 'reports.financial'),
  ('33333333-3333-3333-3333-333333333333', 'audit.read'),
  ('33333333-3333-3333-3333-333333333333', 'portal.customer')
ON CONFLICT DO NOTHING;

-- 3) Admin staff user
INSERT INTO app_user (
  id, company_id, email, display_name, external_subject, user_type, party_id,
  employee_id, is_active, phone, job_title, created_by, last_seen_at,
  deactivated_at, deactivation_reason, row_version, created_at, updated_at
) VALUES (
  '22222222-2222-2222-2222-222222222222',
  '11111111-1111-1111-1111-111111111111',
  'admin@dos.local',
  'DOS Admin',
  NULL,
  'staff',
  NULL,
  NULL,
  TRUE,
  NULL,
  'System Administrator',
  NULL,
  NULL,
  NULL,
  NULL,
  1,
  NOW(),
  NOW()
) ON CONFLICT (id) DO NOTHING;

INSERT INTO user_role (
  id, company_id, user_id, role_id, valid_from, valid_to, created_at, updated_at
) VALUES (
  'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
  '11111111-1111-1111-1111-111111111111',
  '22222222-2222-2222-2222-222222222222',
  '33333333-3333-3333-3333-333333333333',
  NULL,
  NULL,
  NOW(),
  NOW()
) ON CONFLICT (user_id, role_id) DO NOTHING;

-- 4) Customer party (requires catalog migration)
INSERT INTO party (
  id, company_id, code, name, party_type, tin, reg_no, city, region, country,
  address, gps, contact_name, email, phone, payment_terms, credit_limit_minor,
  currency, vat_registered, wht_rule_code, account_owner, is_main_supplier,
  contacts, bank_details, is_active, row_version, created_at, updated_at
) VALUES (
  '55555555-5555-5555-5555-555555555555',
  '11111111-1111-1111-1111-111111111111',
  'CUST-ACME',
  'Acme Trading Ltd',
  'customer',
  NULL,
  NULL,
  'Accra',
  'Greater Accra',
  'GH',
  NULL,
  NULL,
  'Portal Contact',
  'customer@acme.local',
  NULL,
  'Net 30',
  50000000,
  'GHS',
  FALSE,
  NULL,
  NULL,
  FALSE,
  NULL,
  NULL,
  TRUE,
  1,
  NOW(),
  NOW()
) ON CONFLICT (id) DO NOTHING;

-- 5) Customer portal role + user
INSERT INTO role (
  id, company_id, code, name, description, approval_limit_minor,
  is_system, is_active, row_version, created_at, updated_at
) VALUES (
  '44444444-4444-4444-4444-444444444444',
  '11111111-1111-1111-1111-111111111111',
  'customer_portal',
  'Customer Portal',
  'Access customer portal only',
  NULL,
  TRUE,
  TRUE,
  1,
  NOW(),
  NOW()
) ON CONFLICT (id) DO NOTHING;

INSERT INTO role_permission (role_id, permission) VALUES
  ('44444444-4444-4444-4444-444444444444', 'portal.customer')
ON CONFLICT DO NOTHING;

INSERT INTO app_user (
  id, company_id, email, display_name, external_subject, user_type, party_id,
  employee_id, is_active, phone, job_title, created_by, last_seen_at,
  deactivated_at, deactivation_reason, row_version, created_at, updated_at
) VALUES (
  '66666666-6666-6666-6666-666666666666',
  '11111111-1111-1111-1111-111111111111',
  'customer@acme.local',
  'Acme Portal User',
  NULL,
  'portal',
  '55555555-5555-5555-5555-555555555555',
  NULL,
  TRUE,
  NULL,
  NULL,
  '22222222-2222-2222-2222-222222222222',
  NULL,
  NULL,
  NULL,
  1,
  NOW(),
  NOW()
) ON CONFLICT (id) DO NOTHING;

INSERT INTO user_role (
  id, company_id, user_id, role_id, valid_from, valid_to, created_at, updated_at
) VALUES (
  'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
  '11111111-1111-1111-1111-111111111111',
  '66666666-6666-6666-6666-666666666666',
  '44444444-4444-4444-4444-444444444444',
  NULL,
  NULL,
  NOW(),
  NOW()
) ON CONFLICT (user_id, role_id) DO NOTHING;

COMMIT;

-- Dev auth headers:
--   Staff:  x-dev-user: admin@dos.local
--   Portal: x-dev-user: customer@acme.local
