<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * Static permission catalogue consumed by admin UI and OpenAPI.
 *
 * Codes follow frontend convention: module.area.action (or module.action).
 * No separate permission table — codes on role_permission / overrides are the contract
 * (02-database.md, 14.4 notes). This catalogue is the human-readable source for
 * GET /api/v1/admin/permissions.
 *
 * Sensitive flags match 13-users-roles-permissions.md (money movement, approve,
 * period close, access management).
 */
final class PermissionCatalogue
{
    /**
     * @return list<array{code: string, module: string, area: string, action: string, label: string, sensitive: bool}>
     */
    public static function all(): array
    {
        return [
            // Administration
            self::entry('admin.users', 'Administration', 'Users', 'Manage', 'Manage users', true),
            self::entry('admin.roles', 'Administration', 'Roles and permissions', 'Manage', 'Manage roles and permission overrides', true),
            self::entry('admin.templates', 'Administration', 'Document templates', 'Manage', 'Manage document templates', false),

            // Sales
            self::entry('sales.quotes.view', 'Sales', 'Quotations', 'View', 'View quotations', false),
            self::entry('sales.quotes.manage', 'Sales', 'Quotations', 'Manage', 'Create and edit quotations', false),
            self::entry('sales.orders.view', 'Sales', 'Sales orders', 'View', 'View sales orders', false),
            self::entry('sales.orders.manage', 'Sales', 'Sales orders', 'Manage', 'Create and confirm sales orders', false),
            self::entry('sales.manage', 'Sales', 'Sales', 'Manage', 'Broad sales management', false),
            self::entry('customers.view', 'Sales', 'Customers', 'View', 'View customers', false),
            self::entry('customers.manage', 'Sales', 'Customers', 'Manage', 'Create and edit customers', false),

            // Receivables
            self::entry('ar.invoices.view', 'Sales', 'Invoices', 'View', 'View customer invoices', false),
            self::entry('ar.invoice.post', 'Sales', 'Invoices', 'Post', 'Post customer invoices', true),
            self::entry('ar.credit.post', 'Sales', 'Invoices', 'Credit note', 'Post credit notes', true),
            self::entry('ar.receipts.view', 'Sales', 'Receipts', 'View', 'View receipts and advances', false),
            self::entry('ar.receipt.post', 'Sales', 'Receipts', 'Post', 'Post receipts', true),
            self::entry('ar.refund.post', 'Sales', 'Receipts', 'Refund', 'Post refunds', true),

            // Purchasing
            self::entry('po.view', 'Purchasing', 'Purchase orders', 'View', 'View purchase orders', false),
            self::entry('po.create', 'Purchasing', 'Purchase orders', 'Create', 'Create purchase orders', false),
            self::entry('po.approve', 'Purchasing', 'Purchase orders', 'Approve', 'Approve purchase orders', true),
            self::entry('po.cancel', 'Purchasing', 'Purchase orders', 'Cancel', 'Cancel draft or unreceived purchase orders', true),
            self::entry('po.close', 'Purchasing', 'Purchase orders', 'Close', 'Close approved purchase orders', true),
            self::entry('suppliers.view', 'Purchasing', 'Suppliers', 'View', 'View suppliers', false),
            self::entry('suppliers.manage', 'Purchasing', 'Suppliers', 'Manage', 'Create and edit suppliers', false),

            // Payables
            self::entry('ap.bills.view', 'Purchasing', 'Supplier bills', 'View', 'View supplier bills', false),
            self::entry('ap.bill.post', 'Purchasing', 'Supplier bills', 'Post', 'Post supplier bills', true),
            self::entry('ap.payments.view', 'Purchasing', 'Supplier payments', 'View', 'View supplier payments', false),
            self::entry('ap.payment.prepare', 'Purchasing', 'Supplier payments', 'Prepare', 'Prepare supplier payments', true),
            self::entry('ap.payment.confirm', 'Purchasing', 'Supplier payments', 'Confirm', 'Confirm supplier payments', true),

            // Logistics (15.6 — jobs excluded)
            self::entry('logistics.view', 'Logistics', 'Shipments and customs', 'View', 'View shipments, consignments, customs and ETAs', false),
            self::entry('logistics.manage', 'Logistics', 'Shipments', 'Manage', 'Create shipments and record cargo events', false),
            self::entry('logistics.customs', 'Logistics', 'Customs', 'Manage', 'Create and release customs declarations', true),
            self::entry('logistics.costs', 'Logistics', 'Landed cost', 'Enter', 'Enter landed costs against shipments', true),
            self::entry('stock.view', 'Stock', 'Stock on hand', 'View', 'View stock on hand and low-stock alerts', false),
            self::entry('stock.move', 'Stock', 'Receiving and delivery', 'Move', 'Record stock movements', true),
            self::entry('stock.approve', 'Stock', 'Assignments and counts', 'Approve', 'Approve stock adjustments', true),
            self::entry('items.view', 'Stock', 'Items and locations', 'View', 'View items and locations', false),
            self::entry('items.manage', 'Stock', 'Items and locations', 'Manage', 'Manage items and locations', false),

            // Finance / Ledger
            self::entry('finance.view', 'Finance', 'Overview', 'View', 'View finance overview and cash registers', false),
            self::entry('ledger.read', 'Finance', 'Journals and COA', 'View', 'View journals and chart of accounts', false),
            self::entry('ledger.journal.create', 'Finance', 'Journals', 'Create', 'Create draft journals', false),
            self::entry('ledger.journal.post', 'Finance', 'Journals', 'Post', 'Post journals', true),
            self::entry('ledger.accounts.manage', 'Finance', 'Chart of accounts', 'Manage', 'Manage chart of accounts', true),
            self::entry('cash.spend', 'Finance', 'Expenses and transfers', 'Spend', 'Record expenses, cheques and transfers', true),
            self::entry('bank.view', 'Finance', 'Bank reconciliation', 'View', 'View bank reconciliation', false),
            self::entry('bank.match', 'Finance', 'Bank reconciliation', 'Match', 'Match bank lines', false),
            self::entry('bank.approve', 'Finance', 'Bank reconciliation', 'Approve', 'Approve bank reconciliation', true),
            self::entry('fx.manage', 'Finance', 'Exchange rates', 'Manage', 'Manage FX rates', false),
            self::entry('tax.manage', 'Finance', 'Tax rules', 'Manage', 'Manage tax and WHT rules', true),
            self::entry('period.close', 'Finance', 'Period close', 'Close', 'Close accounting periods', true),

            // Reports
            self::entry('reports.financial', 'Reports', 'Financial reports', 'View', 'View financial reports and ledger', false),
            self::entry('reports.performance', 'Reports', 'Performance centre', 'View', 'View performance centre', false),
            self::entry('reports.export', 'Reports', 'Exports', 'Export', 'Export reports (Excel/CSV)', false),
            self::entry('audit.read', 'Reports', 'Audit trail', 'View', 'View audit trail', false),

            // Payroll
            self::entry('payroll.manage', 'Payroll', 'Payroll runs', 'Prepare', 'Prepare payroll runs', true),
            self::entry('payroll.approve', 'Payroll', 'Payroll runs', 'Approve', 'Approve and post payroll', true),

            // Portals
            self::entry('portal.customer', 'Portals', 'Customer portal', 'Access', 'Access customer portal', false),
            self::entry('portal.supplier', 'Portals', 'Supplier portal', 'Access', 'Access supplier portal', false),
        ];
    }

    /**
     * @return array<string, list<array{code: string, module: string, area: string, action: string, label: string, sensitive: bool}>>
     */
    public static function groupedByModule(): array
    {
        $grouped = [];
        foreach (self::all() as $entry) {
            $grouped[$entry['module']][] = $entry;
        }

        return $grouped;
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(static fn (array $e) => $e['code'], self::all());
    }

    public static function isKnown(string $code): bool
    {
        return in_array($code, self::codes(), true);
    }

    /**
     * @return array{code: string, module: string, area: string, action: string, label: string, sensitive: bool}
     */
    private static function entry(
        string $code,
        string $module,
        string $area,
        string $action,
        string $label,
        bool $sensitive,
    ): array {
        return [
            'code' => $code,
            'module' => $module,
            'area' => $area,
            'action' => $action,
            'label' => $label,
            'sensitive' => $sensitive,
        ];
    }
}
