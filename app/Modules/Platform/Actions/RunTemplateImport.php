<?php

declare(strict_types=1);

namespace App\Modules\Platform\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use App\Models\Account;
use App\Models\BankImport;
use App\Models\BankStatementLine;
use App\Models\Bill;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Item;
use App\Models\Location;
use App\Models\Party;
use App\Models\StockMovement;
use App\Modules\Platform\Domain\ImportDefinitions;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Outbox\OutboxWriter;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/imports/{type}
 *
 * Template-based, all-or-nothing import.
 * Every row is validated against the template derived from the real table.
 * If any row is invalid the whole file is rejected and every row/column reason is returned.
 * On success all rows are written in one transaction.
 */
final class RunTemplateImport
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
        private readonly OutboxWriter $outboxWriter,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function execute(string $type, array $data): array
    {
        $def = ImportDefinitions::get($type);
        if ($def === null) {
            throw new BusinessRuleException('Unknown import type.', 'unknown_import_type');
        }

        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, $def['permission']);

        $companyId = $this->companyContext->companyId();
        $csv = (string) ($data['csv'] ?? '');
        if (trim($csv) === '') {
            throw new ValidationException('csv content is required.', ['csv' => ['Required']]);
        }

        $rows = $this->parseCsv($csv);
        if (count($rows) < 2) {
            throw new ValidationException('CSV must contain a header row and at least one data row.', ['csv' => ['Empty']]);
        }

        $header = array_map('trim', array_shift($rows));
        $expected = array_column($def['columns'], 'name');
        $missing = array_diff($expected, $header);
        if ($missing !== []) {
            throw new ValidationException(
                'Template header does not match. Missing columns: '.implode(', ', $missing),
                ['header' => ['Missing: '.implode(', ', $missing)]],
            );
        }

        $index = array_flip($header);
        $errors = [];
        $parsed = [];

        // Preload lookup maps
        $partyByCode = Party::query()->where('company_id', $companyId)->pluck('id', 'code')->all();
        $itemBySku = Item::query()->where('company_id', $companyId)->pluck('id', 'sku')->all();
        $locationByCode = Location::query()->where('company_id', $companyId)->pluck('id', 'code')->all();
        $accountByCode = Account::query()->where('company_id', $companyId)->pluck('id', 'code')->all();

        $seenKeys = [];

        foreach ($rows as $i => $raw) {
            $rowNo = $i + 2; // 1-based, header is row 1
            $row = [];
            foreach ($def['columns'] as $col) {
                $idx = $index[$col['name']] ?? null;
                $value = $idx !== null ? trim((string) ($raw[$idx] ?? '')) : '';
                $row[$col['name']] = $value;

                if ($col['required'] && $value === '') {
                    $errors[] = ['row' => $rowNo, 'column' => $col['name'], 'reason' => 'Required'];
                    continue;
                }
                if ($value === '') {
                    continue;
                }

                $reason = $this->checkType($col['type'], $value);
                if ($reason !== null) {
                    $errors[] = ['row' => $rowNo, 'column' => $col['name'], 'reason' => $reason];
                }
            }

            // Type-specific FK and uniqueness checks
            $this->checkReferences($type, $row, $rowNo, $partyByCode, $itemBySku, $locationByCode, $accountByCode, $errors);

            $key = $this->uniqueKey($type, $row);
            if ($key !== null) {
                if (isset($seenKeys[$key])) {
                    $errors[] = ['row' => $rowNo, 'column' => $this->keyColumn($type), 'reason' => 'Duplicate in file'];
                } else {
                    $seenKeys[$key] = true;
                }
            }

            $parsed[] = ['rowNo' => $rowNo, 'data' => $row];
        }

        if ($errors !== []) {
            throw ValidationException::fromFields(
                ['rows' => $errors],
                'Import rejected. '.count($errors).' error(s). Nothing was imported.',
            );
        }

        return DB::transaction(function () use ($type, $parsed, $companyId, $actorId, $partyByCode, $itemBySku, $locationByCode, $accountByCode) {
            $bankImportId = null;
            if ($type === 'bank-statement' && $parsed !== []) {
                $first = $parsed[0]['data'];
                $bankImportId = Uuid::v7();
                BankImport::query()->create([
                    'id' => $bankImportId,
                    'company_id' => $companyId,
                    'bank_account_id' => $accountByCode[$first['bank_account_code']],
                    'file_name' => 'template-import',
                    'file_hash' => hash('sha256', ($this->companyContext->requestId() ?? '').'|'.now()->toIso8601String()),
                    'column_mapping' => ['template' => 'bank-statement'],
                    'line_count' => count($parsed),
                    'skipped_count' => 0,
                    'imported_by' => $actorId,
                    'imported_at' => now(),
                    'request_id' => $this->companyContext->requestId(),
                ]);
            }

            $count = $this->insert($type, $parsed, $companyId, $actorId, $partyByCode, $itemBySku, $locationByCode, $accountByCode, $bankImportId);

            $this->auditWriter->write(
                companyId: $companyId,
                actorId: $actorId,
                action: 'import.'.$type,
                subjectType: 'import',
                subjectId: $type,
                payload: ['type' => $type, 'rowCount' => $count],
                requestId: $this->companyContext->requestId(),
            );

            $this->outboxWriter->write(
                companyId: $companyId,
                type: 'import.completed',
                payload: ['importType' => $type, 'rowCount' => $count],
                requestId: $this->companyContext->requestId(),
            );

            return [
                'type' => $type,
                'imported' => $count,
                'message' => $count.' row(s) imported.',
            ];
        });
    }

    /**
     * @param  array<string, string>  $row
     * @param  list<array{row: int, column: string, reason: string}>  $errors
     */
    private function checkReferences(
        string $type,
        array $row,
        int $rowNo,
        array $partyByCode,
        array $itemBySku,
        array $locationByCode,
        array $accountByCode,
        array &$errors,
    ): void {
        if (in_array($type, ['customers', 'suppliers'], true) && $row['code'] !== '' && isset($partyByCode[$row['code']])) {
            $errors[] = ['row' => $rowNo, 'column' => 'code', 'reason' => 'Already exists'];
        }
        if ($type === 'items' && $row['sku'] !== '' && isset($itemBySku[$row['sku']])) {
            $errors[] = ['row' => $rowNo, 'column' => 'sku', 'reason' => 'Already exists'];
        }
        if ($type === 'accounts' && $row['code'] !== '' && isset($accountByCode[$row['code']])) {
            $errors[] = ['row' => $rowNo, 'column' => 'code', 'reason' => 'Already exists'];
        }
        if ($type === 'receivables') {
            if ($row['customer_code'] !== '' && ! isset($partyByCode[$row['customer_code']])) {
                $errors[] = ['row' => $rowNo, 'column' => 'customer_code', 'reason' => 'Customer not found'];
            }
            if ($row['invoice_no'] !== '' && Invoice::query()->where('company_id', $this->companyContext->companyId())->where('invoice_no', $row['invoice_no'])->exists()) {
                $errors[] = ['row' => $rowNo, 'column' => 'invoice_no', 'reason' => 'Already exists'];
            }
        }
        if ($type === 'payables') {
            if ($row['supplier_code'] !== '' && ! isset($partyByCode[$row['supplier_code']])) {
                $errors[] = ['row' => $rowNo, 'column' => 'supplier_code', 'reason' => 'Supplier not found'];
            }
            if ($row['bill_no'] !== '' && Bill::query()->where('company_id', $this->companyContext->companyId())->where('bill_no', $row['bill_no'])->exists()) {
                $errors[] = ['row' => $rowNo, 'column' => 'bill_no', 'reason' => 'Already exists'];
            }
            if ($row['expense_account_code'] !== '' && ! isset($accountByCode[$row['expense_account_code']])) {
                $errors[] = ['row' => $rowNo, 'column' => 'expense_account_code', 'reason' => 'Account not found'];
            }
        }
        if ($type === 'delivery') {
            if ($row['item_sku'] !== '' && ! isset($itemBySku[$row['item_sku']])) {
                $errors[] = ['row' => $rowNo, 'column' => 'item_sku', 'reason' => 'Item not found'];
            }
            if ($row['location_code'] !== '' && ! isset($locationByCode[$row['location_code']])) {
                $errors[] = ['row' => $rowNo, 'column' => 'location_code', 'reason' => 'Location not found'];
            }
        }
        if ($type === 'bank-statement' && $row['bank_account_code'] !== '' && ! isset($accountByCode[$row['bank_account_code']])) {
            $errors[] = ['row' => $rowNo, 'column' => 'bank_account_code', 'reason' => 'Account not found'];
        }
    }

    /**
     * @param  list<array{rowNo: int, data: array<string, string>}>  $parsed
     */
    private function insert(
        string $type,
        array $parsed,
        string $companyId,
        ?string $actorId,
        array $partyByCode,
        array $itemBySku,
        array $locationByCode,
        array $accountByCode,
        ?string $bankImportId = null,
    ): int {
        $count = 0;

        foreach ($parsed as $p) {
            $r = $p['data'];
            match ($type) {
                'items' => Item::query()->create([
                    'id' => Uuid::v7(),
                    'company_id' => $companyId,
                    'sku' => $r['sku'],
                    'name' => $r['name'],
                    'item_type' => $r['item_type'],
                    'category_code' => $r['category_code'] ?: null,
                    'unit' => $r['unit'] ?: 'pcs',
                    'list_price_minor' => $this->money($r['list_price']),
                    'standard_cost_minor' => $this->money($r['standard_cost']),
                    'buy_price_minor' => $r['buy_price'] !== '' ? $this->money($r['buy_price']) : null,
                    'weight_kg' => $r['weight_kg'] !== '' ? $r['weight_kg'] : null,
                    'inventory_policy' => $r['inventory_policy'] ?: 'Buy',
                    'lot_controlled' => $this->bool($r['lot_controlled'], true),
                    'reorder_point' => $r['reorder_point'] !== '' ? $r['reorder_point'] : null,
                    'safety_stock' => $r['safety_stock'] !== '' ? $r['safety_stock'] : null,
                    'inventory_account_code' => $r['inventory_account_code'] ?: null,
                    'is_active' => $this->bool($r['is_active'], true),
                ]),
                'customers', 'suppliers' => Party::query()->create([
                    'id' => Uuid::v7(),
                    'company_id' => $companyId,
                    'code' => $r['code'],
                    'name' => $r['name'],
                    'party_type' => $type === 'customers' ? 'customer' : 'supplier',
                    'tin' => $r['tin'] ?: null,
                    'reg_no' => $r['reg_no'] ?: null,
                    'city' => $r['city'] ?: null,
                    'region' => $r['region'] ?: null,
                    'country' => $r['country'] ?: null,
                    'address' => $r['address'] ?: null,
                    'contact_name' => $r['contact_name'] ?: null,
                    'email' => $r['email'] ?: null,
                    'phone' => $r['phone'] ?: null,
                    'payment_terms' => $r['payment_terms'] ?: null,
                    'credit_limit_minor' => $r['credit_limit'] !== '' ? $this->money($r['credit_limit']) : null,
                    'currency' => $r['currency'] ?: 'GHS',
                    'vat_registered' => $this->bool($r['vat_registered'], false),
                    'is_active' => $this->bool($r['is_active'], true),
                ]),
                'accounts' => Account::query()->create([
                    'id' => Uuid::v7(),
                    'company_id' => $companyId,
                    'code' => $r['code'],
                    'name' => $r['name'],
                    'category' => $r['category'],
                    'kind' => $r['kind'] ?: 'detail',
                    'currency' => $r['currency'] ?: null,
                    'fs_line_item' => $r['fs_line_item'] ?: null,
                    'fs_sub_line' => $r['fs_sub_line'] ?: null,
                    'normal_balance' => $r['normal_balance'] ?: 'debit',
                    'is_active' => $this->bool($r['is_active'], true),
                ]),
                'receivables' => $this->insertInvoice($r, $companyId, $actorId, $partyByCode),
                'payables' => $this->insertBill($r, $companyId, $actorId, $partyByCode, $accountByCode),
                'delivery' => StockMovement::query()->create([
                    'company_id' => $companyId,
                    'movement_type' => 'delivery',
                    'item_id' => $itemBySku[$r['item_sku']],
                    'location_id' => $locationByCode[$r['location_code']],
                    'lot' => $r['lot'] ?: null,
                    'qty' => -abs((float) $r['qty']),
                    'unit_cost_minor' => $this->money($r['unit_cost']),
                    'movement_date' => $r['movement_date'],
                    'source_type' => 'import',
                    'memo' => $r['memo'] ?: null,
                    'created_by' => $actorId,
                    'request_id' => $this->companyContext->requestId(),
                ]),
                'bank-statement' => $this->insertBankLine($r, $companyId, $accountByCode, $bankImportId),
                default => null,
            };
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<string, string>  $r
     * @param  array<string, string>  $partyByCode
     */
    private function insertInvoice(array $r, string $companyId, ?string $actorId, array $partyByCode): void
    {
        $id = Uuid::v7();
        $net = $this->money($r['net']);
        $tax = $this->money($r['tax']);
        $gross = $this->money($r['gross']);

        Invoice::query()->create([
            'id' => $id,
            'company_id' => $companyId,
            'invoice_no' => $r['invoice_no'],
            'customer_id' => $partyByCode[$r['customer_code']],
            'invoice_date' => $r['invoice_date'],
            'due_date' => $r['due_date'] ?: null,
            'currency' => $r['currency'] ?: 'GHS',
            'net_minor' => $net,
            'vat_minor' => $tax,
            'tax_total_minor' => $tax,
            'gross_minor' => $gross,
            'status' => 'posted',
            'memo' => $r['memo'] ?: 'Imported',
            'created_by' => $actorId,
            'request_id' => $this->companyContext->requestId(),
        ]);

        InvoiceLine::query()->create([
            'id' => Uuid::v7(),
            'company_id' => $companyId,
            'invoice_id' => $id,
            'line_no' => 1,
            'description' => $r['description'] ?: 'Imported line',
            'qty' => 1,
            'unit' => 'pcs',
            'unit_price_minor' => $net,
            'net_minor' => $net,
            'vat_minor' => $tax,
            'gross_minor' => $gross,
        ]);
    }

    /**
     * @param  array<string, string>  $r
     */
    private function insertBill(array $r, string $companyId, ?string $actorId, array $partyByCode, array $accountByCode): void
    {
        Bill::query()->create([
            'id' => Uuid::v7(),
            'company_id' => $companyId,
            'bill_no' => $r['bill_no'],
            'supplier_id' => $partyByCode[$r['supplier_code']],
            'supplier_ref' => $r['supplier_ref'] ?: null,
            'bill_date' => $r['bill_date'],
            'due_date' => $r['due_date'] ?: null,
            'currency' => $r['currency'] ?: 'GHS',
            'net_minor' => $this->money($r['net']),
            'tax_minor' => $this->money($r['tax']),
            'gross_minor' => $this->money($r['gross']),
            'expense_account_id' => $r['expense_account_code'] !== '' ? ($accountByCode[$r['expense_account_code']] ?? null) : null,
            'status' => 'posted',
            'memo' => $r['memo'] ?: 'Imported',
            'created_by' => $actorId,
            'request_id' => $this->companyContext->requestId(),
        ]);
    }

    /**
     * @param  array<string, string>  $r
     */
    private function insertBankLine(array $r, string $companyId, array $accountByCode, ?string $bankImportId): void
    {
        $amountMinor = $this->money($r['amount']);
        $direction = $r['direction'] !== '' ? strtolower($r['direction']) : ($amountMinor >= 0 ? 'credit' : 'debit');
        $accountId = $accountByCode[$r['bank_account_code']];
        $lineHash = hash('sha256', implode('|', [$accountId, $r['txn_date'], $r['reference'], $r['description'], (string) abs($amountMinor), $direction]));

        BankStatementLine::query()->create([
            'company_id' => $companyId,
            'bank_import_id' => $bankImportId,
            'bank_account_id' => $accountId,
            'txn_date' => $r['txn_date'],
            'reference' => $r['reference'] ?: null,
            'description' => $r['description'] ?: null,
            'amount_minor' => abs($amountMinor),
            'direction' => $direction,
            'currency' => 'GHS',
            'status' => 'unmatched',
            'line_hash' => $lineHash,
        ]);
    }

    private function checkType(string $type, string $value): ?string
    {
        return match ($type) {
            'money', 'decimal' => is_numeric(str_replace([',', ' '], '', $value)) ? null : 'Must be a number',
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false ? null : 'Must be YYYY-MM-DD',
            'bool' => in_array(strtolower($value), ['true', 'false', '1', '0', 'yes', 'no'], true) ? null : 'Must be true or false',
            default => null,
        };
    }

    private function money(string $value): int
    {
        if ($value === '') {
            return 0;
        }
        $clean = str_replace([',', ' '], '', $value);
        return (int) round((float) $clean * 100);
    }

    private function bool(string $value, bool $default): bool
    {
        if ($value === '') {
            return $default;
        }
        return in_array(strtolower($value), ['true', '1', 'yes'], true);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function uniqueKey(string $type, array $row): ?string
    {
        return match ($type) {
            'items' => $row['sku'] ?? null,
            'customers', 'suppliers', 'accounts' => $row['code'] ?? null,
            'receivables' => $row['invoice_no'] ?? null,
            'payables' => $row['bill_no'] ?? null,
            default => null,
        };
    }

    private function keyColumn(string $type): string
    {
        return match ($type) {
            'items' => 'sku',
            'receivables' => 'invoice_no',
            'payables' => 'bill_no',
            default => 'code',
        };
    }

    /**
     * @return list<list<string>>
     */
    private function parseCsv(string $csv): array
    {
        $csv = str_replace(["\r\n", "\r"], "\n", $csv);
        $lines = array_values(array_filter(explode("\n", $csv), fn ($l) => trim($l) !== ''));
        return array_map(fn ($l) => str_getcsv($l), $lines);
    }
}
