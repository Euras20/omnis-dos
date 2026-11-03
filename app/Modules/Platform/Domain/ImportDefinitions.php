<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain;

/**
 * Template definitions derived from the real tables the import will fill.
 * Columns are the exact fields the user supplies; the importer resolves codes
 * to FKs and converts money (major units) to minor units before insert.
 */
final class ImportDefinitions
{
    /**
     * @return array<string, array{label: string, permission: string, columns: list<array{name: string, required: bool, type: string, hint: string}>}>
     */
    public static function all(): array
    {
        return [
            'items' => [
                'label' => 'Items',
                'permission' => 'items.manage',
                'columns' => [
                    ['name' => 'sku', 'required' => true, 'type' => 'string', 'hint' => 'Unique item code'],
                    ['name' => 'name', 'required' => true, 'type' => 'string', 'hint' => 'Item name'],
                    ['name' => 'item_type', 'required' => true, 'type' => 'string', 'hint' => 'Trading good | Spare part | Packaging | Service'],
                    ['name' => 'category_code', 'required' => false, 'type' => 'string', 'hint' => 'Optional category code'],
                    ['name' => 'unit', 'required' => false, 'type' => 'string', 'hint' => 'Default pcs'],
                    ['name' => 'list_price', 'required' => false, 'type' => 'money', 'hint' => 'Major units e.g. 1234.56'],
                    ['name' => 'standard_cost', 'required' => false, 'type' => 'money', 'hint' => 'Major units'],
                    ['name' => 'buy_price', 'required' => false, 'type' => 'money', 'hint' => 'Major units'],
                    ['name' => 'weight_kg', 'required' => false, 'type' => 'decimal', 'hint' => 'Optional'],
                    ['name' => 'inventory_policy', 'required' => false, 'type' => 'string', 'hint' => 'Buy | Make'],
                    ['name' => 'lot_controlled', 'required' => false, 'type' => 'bool', 'hint' => 'true | false'],
                    ['name' => 'reorder_point', 'required' => false, 'type' => 'decimal', 'hint' => 'Optional'],
                    ['name' => 'safety_stock', 'required' => false, 'type' => 'decimal', 'hint' => 'Optional'],
                    ['name' => 'inventory_account_code', 'required' => false, 'type' => 'string', 'hint' => 'COA code'],
                    ['name' => 'is_active', 'required' => false, 'type' => 'bool', 'hint' => 'Default true'],
                ],
            ],
            'customers' => [
                'label' => 'Customers',
                'permission' => 'customers.manage',
                'columns' => self::partyColumns(),
            ],
            'suppliers' => [
                'label' => 'Suppliers',
                'permission' => 'suppliers.manage',
                'columns' => self::partyColumns(),
            ],
            'accounts' => [
                'label' => 'Chart of accounts',
                'permission' => 'ledger.accounts.manage',
                'columns' => [
                    ['name' => 'code', 'required' => true, 'type' => 'string', 'hint' => 'Unique account code'],
                    ['name' => 'name', 'required' => true, 'type' => 'string', 'hint' => 'Account name'],
                    ['name' => 'category', 'required' => true, 'type' => 'string', 'hint' => 'asset | liability | equity | income | expense'],
                    ['name' => 'kind', 'required' => false, 'type' => 'string', 'hint' => 'header | detail (default detail)'],
                    ['name' => 'currency', 'required' => false, 'type' => 'string', 'hint' => 'ISO code or blank'],
                    ['name' => 'fs_line_item', 'required' => false, 'type' => 'string', 'hint' => 'Optional FS mapping'],
                    ['name' => 'fs_sub_line', 'required' => false, 'type' => 'string', 'hint' => 'Optional'],
                    ['name' => 'normal_balance', 'required' => false, 'type' => 'string', 'hint' => 'debit | credit'],
                    ['name' => 'is_active', 'required' => false, 'type' => 'bool', 'hint' => 'Default true'],
                ],
            ],
            'receivables' => [
                'label' => 'Receivables (open invoices)',
                'permission' => 'ar.invoice.post',
                'columns' => [
                    ['name' => 'invoice_no', 'required' => true, 'type' => 'string', 'hint' => 'Unique invoice number'],
                    ['name' => 'customer_code', 'required' => true, 'type' => 'string', 'hint' => 'Existing customer code'],
                    ['name' => 'invoice_date', 'required' => true, 'type' => 'date', 'hint' => 'YYYY-MM-DD'],
                    ['name' => 'due_date', 'required' => false, 'type' => 'date', 'hint' => 'YYYY-MM-DD'],
                    ['name' => 'currency', 'required' => false, 'type' => 'string', 'hint' => 'Default GHS'],
                    ['name' => 'net', 'required' => true, 'type' => 'money', 'hint' => 'Net amount major units'],
                    ['name' => 'tax', 'required' => false, 'type' => 'money', 'hint' => 'Tax major units'],
                    ['name' => 'gross', 'required' => true, 'type' => 'money', 'hint' => 'Gross major units'],
                    ['name' => 'description', 'required' => false, 'type' => 'string', 'hint' => 'Line description'],
                    ['name' => 'memo', 'required' => false, 'type' => 'string', 'hint' => 'Optional'],
                ],
            ],
            'payables' => [
                'label' => 'Payables (open bills)',
                'permission' => 'ap.bill.post',
                'columns' => [
                    ['name' => 'bill_no', 'required' => true, 'type' => 'string', 'hint' => 'Unique bill number'],
                    ['name' => 'supplier_code', 'required' => true, 'type' => 'string', 'hint' => 'Existing supplier code'],
                    ['name' => 'supplier_ref', 'required' => false, 'type' => 'string', 'hint' => 'Supplier invoice ref'],
                    ['name' => 'bill_date', 'required' => true, 'type' => 'date', 'hint' => 'YYYY-MM-DD'],
                    ['name' => 'due_date', 'required' => false, 'type' => 'date', 'hint' => 'YYYY-MM-DD'],
                    ['name' => 'currency', 'required' => false, 'type' => 'string', 'hint' => 'Default GHS'],
                    ['name' => 'net', 'required' => true, 'type' => 'money', 'hint' => 'Net major units'],
                    ['name' => 'tax', 'required' => false, 'type' => 'money', 'hint' => 'Tax major units'],
                    ['name' => 'gross', 'required' => true, 'type' => 'money', 'hint' => 'Gross major units'],
                    ['name' => 'expense_account_code', 'required' => false, 'type' => 'string', 'hint' => 'COA code'],
                    ['name' => 'memo', 'required' => false, 'type' => 'string', 'hint' => 'Optional'],
                ],
            ],
            'delivery' => [
                'label' => 'Delivery (stock issues)',
                'permission' => 'stock.move',
                'columns' => [
                    ['name' => 'item_sku', 'required' => true, 'type' => 'string', 'hint' => 'Existing item SKU'],
                    ['name' => 'location_code', 'required' => true, 'type' => 'string', 'hint' => 'Existing location code'],
                    ['name' => 'lot', 'required' => false, 'type' => 'string', 'hint' => 'Optional lot'],
                    ['name' => 'qty', 'required' => true, 'type' => 'decimal', 'hint' => 'Positive quantity issued'],
                    ['name' => 'unit_cost', 'required' => false, 'type' => 'money', 'hint' => 'Major units'],
                    ['name' => 'movement_date', 'required' => true, 'type' => 'date', 'hint' => 'YYYY-MM-DD'],
                    ['name' => 'memo', 'required' => false, 'type' => 'string', 'hint' => 'Optional'],
                ],
            ],
            'bank-statement' => [
                'label' => 'Bank statement',
                'permission' => 'bank.match',
                'columns' => [
                    ['name' => 'bank_account_code', 'required' => true, 'type' => 'string', 'hint' => 'COA code of the bank account'],
                    ['name' => 'txn_date', 'required' => true, 'type' => 'date', 'hint' => 'YYYY-MM-DD'],
                    ['name' => 'reference', 'required' => false, 'type' => 'string', 'hint' => 'Optional'],
                    ['name' => 'description', 'required' => false, 'type' => 'string', 'hint' => 'Optional'],
                    ['name' => 'amount', 'required' => true, 'type' => 'money', 'hint' => 'Signed major units (credit positive)'],
                    ['name' => 'direction', 'required' => false, 'type' => 'string', 'hint' => 'credit | debit (inferred from amount if blank)'],
                ],
            ],
        ];
    }

    /**
     * @return list<array{name: string, required: bool, type: string, hint: string}>
     */
    private static function partyColumns(): array
    {
        return [
            ['name' => 'code', 'required' => true, 'type' => 'string', 'hint' => 'Unique party code'],
            ['name' => 'name', 'required' => true, 'type' => 'string', 'hint' => 'Legal / trading name'],
            ['name' => 'tin', 'required' => false, 'type' => 'string', 'hint' => 'Optional'],
            ['name' => 'reg_no', 'required' => false, 'type' => 'string', 'hint' => 'Optional'],
            ['name' => 'city', 'required' => false, 'type' => 'string', 'hint' => 'Optional'],
            ['name' => 'region', 'required' => false, 'type' => 'string', 'hint' => 'Optional'],
            ['name' => 'country', 'required' => false, 'type' => 'string', 'hint' => 'Optional'],
            ['name' => 'address', 'required' => false, 'type' => 'string', 'hint' => 'Billing address'],
            ['name' => 'delivery_address', 'required' => false, 'type' => 'string', 'hint' => 'Delivery address'],
            ['name' => 'delivery_terms', 'required' => false, 'type' => 'string', 'hint' => 'EXW | FCA | FOB | CIF | DAP | DDP'],
            ['name' => 'contact_name', 'required' => false, 'type' => 'string', 'hint' => 'Optional'],
            ['name' => 'email', 'required' => false, 'type' => 'string', 'hint' => 'Optional'],
            ['name' => 'phone', 'required' => false, 'type' => 'string', 'hint' => 'Optional'],
            ['name' => 'payment_terms', 'required' => false, 'type' => 'string', 'hint' => 'Optional'],
            ['name' => 'credit_limit', 'required' => false, 'type' => 'money', 'hint' => 'Major units'],
            ['name' => 'currency', 'required' => false, 'type' => 'string', 'hint' => 'Default GHS'],
            ['name' => 'vat_registered', 'required' => false, 'type' => 'bool', 'hint' => 'true | false'],
            ['name' => 'is_active', 'required' => false, 'type' => 'bool', 'hint' => 'Default true'],
        ];
    }

    /**
     * @return array{label: string, permission: string, columns: list<array{name: string, required: bool, type: string, hint: string}>}|null
     */
    public static function get(string $type): ?array
    {
        return self::all()[$type] ?? null;
    }

    /**
     * One example data row for the downloadable template.
     * Users replace or delete it before upload. Values are illustrative only.
     *
     * @return list<string>
     */
    public static function sampleRow(string $type): array
    {
        return match ($type) {
            'items' => ['TR-9001', 'Sample Valve', 'Trading good', '', 'pcs', '150.00', '80.00', '75.00', '1.2', 'Buy', 'true', '10', '5', '1400', 'true'],
            'customers' => ['C-SAMPLE-01', 'Sample Customer Ltd', 'C0000000001', '', 'Accra', 'Greater Accra', 'GH', 'Plot 1', 'Warehouse 2', 'CIF', 'Ama Mensah', 'ama@sample.com', '+233200000000', 'Net 30', '50000.00', 'GHS', 'true', 'true'],
            'suppliers' => ['S-SAMPLE-01', 'Sample Supplier Ltd', '', '', 'Tema', 'Greater Accra', 'GH', 'Industrial Area', '', 'FOB', '', 'supplier@sample.com', '+233200000001', 'Net 15', '', 'GHS', 'false', 'true'],
            'accounts' => ['9999', 'Sample Expense', 'expense', 'detail', 'GHS', '', '', 'debit', 'true'],
            'receivables' => ['INV-SAMPLE-001', 'C-SAMPLE-01', '2026-10-01', '2026-10-31', 'GHS', '1000.00', '150.00', '1150.00', 'Sample line', 'Imported sample'],
            'payables' => ['BILL-SAMPLE-001', 'S-SAMPLE-01', 'SUP-INV-1', '2026-10-01', '2026-10-31', 'GHS', '500.00', '75.00', '575.00', '', 'Imported sample'],
            'delivery' => ['TR-9001', 'TEM-GEN', 'LOT-1', '5', '80.00', '2026-10-15', 'Sample delivery'],
            'bank-statement' => ['1000', '2026-10-15', 'REF-1', 'Customer payment', '1500.00', 'credit'],
            default => [],
        };
    }

}
