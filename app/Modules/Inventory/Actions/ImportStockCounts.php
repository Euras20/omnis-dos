<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\Item;
use App\Models\Location;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/stock/counts/import
 *
 * Reads the stock-count CSV (the downloaded template, filled in).
 * Rows with a blank counted_qty are skipped.
 * Groups by location and opens one draft count per location via CreateStockCount.
 * All or nothing: any bad row rejects the whole file. Existing counts are not changed.
 */
final class ImportStockCounts
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly CreateStockCount $createStockCount,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $csv, ?string $countDate = null): array
    {
        $this->permissionChecker->assertCan($this->companyContext->userId(), 'stock.move');

        $companyId = $this->companyContext->companyId();
        $date = $countDate !== null && $countDate !== '' ? $countDate : now()->toDateString();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new BusinessRuleException('countDate must be YYYY-MM-DD.');
        }

        $rows = $this->parse($csv);
        if ($rows === []) {
            throw new BusinessRuleException('The file has no counted rows. Fill counted_qty for the items you counted.');
        }

        $items = Item::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->get(['id', 'sku']);
        $itemById = $items->keyBy('id');
        $itemBySku = $items->keyBy(static fn ($item) => strtolower((string) $item->sku));

        $locations = Location::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->get(['id', 'code']);
        $locationByCode = $locations->keyBy(static fn ($location) => strtolower((string) $location->code));

        /** @var array<string, list<array<string, mixed>>> $byLocation */
        $byLocation = [];
        $errors = [];

        foreach ($rows as $row) {
            $lineNo = $row['line'];
            $item = null;
            if ($row['item_id'] !== '' && $itemById->has($row['item_id'])) {
                $item = $itemById->get($row['item_id']);
            } elseif ($row['sku'] !== '' && $itemBySku->has(strtolower($row['sku']))) {
                $item = $itemBySku->get(strtolower($row['sku']));
            }
            if ($item === null) {
                $errors[] = "Row {$lineNo}: item not found (item_id or sku).";
                continue;
            }

            if ($row['location_code'] === '') {
                $errors[] = "Row {$lineNo}: location_code is required.";
                continue;
            }
            $location = $locationByCode->get(strtolower($row['location_code']));
            if ($location === null) {
                $errors[] = "Row {$lineNo}: location '{$row['location_code']}' not found.";
                continue;
            }

            if (! is_numeric($row['counted_qty'])) {
                $errors[] = "Row {$lineNo}: counted_qty must be a number.";
                continue;
            }

            $byLocation[(string) $location->id][] = [
                'itemId' => (string) $item->id,
                'lot' => $row['lot'] !== '' ? $row['lot'] : null,
                'countedQty' => (float) $row['counted_qty'],
                'reason' => $row['reason'] !== '' ? $row['reason'] : null,
            ];
        }

        if ($errors !== []) {
            throw new BusinessRuleException(implode(' ', array_slice($errors, 0, 8)));
        }

        return DB::transaction(function () use ($byLocation, $date) {
            $created = [];
            foreach ($byLocation as $locationId => $lines) {
                $created[] = $this->createStockCount->execute([
                    'locationId' => $locationId,
                    'countDate' => $date,
                    'memo' => 'Imported from stock-count sheet',
                    'lines' => $lines,
                ]);
            }

            return [
                'countDate' => $date,
                'sheets' => count($created),
                'lines' => array_sum(array_map(static fn ($c) => count($c['lines'] ?? []), $created)),
                'counts' => $created,
            ];
        });
    }

    /**
     * @return list<array{line: int, item_id: string, sku: string, location_code: string, lot: string, counted_qty: string, reason: string}>
     */
    private function parse(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            throw new BusinessRuleException('The file is empty.');
        }
        $header = array_map(static fn ($h) => strtolower(trim((string) $h)), $header);
        $index = array_flip($header);
        foreach (['item_id', 'sku', 'location_code', 'counted_qty'] as $required) {
            if (! isset($index[$required])) {
                fclose($handle);
                throw new BusinessRuleException("Missing column '{$required}'. Use the downloaded template.");
            }
        }

        $rows = [];
        $lineNo = 1;
        while (($cols = fgetcsv($handle)) !== false) {
            $lineNo++;
            if ($cols === [null] || $cols === []) {
                continue;
            }
            $value = static function (array $cols, array $index, string $key): string {
                $i = $index[$key] ?? null;
                if ($i === null || ! isset($cols[$i])) {
                    return '';
                }

                return trim((string) $cols[$i]);
            };
            $counted = $value($cols, $index, 'counted_qty');
            if ($counted === '') {
                continue;
            }
            $rows[] = [
                'line' => $lineNo,
                'item_id' => $value($cols, $index, 'item_id'),
                'sku' => $value($cols, $index, 'sku'),
                'location_code' => $value($cols, $index, 'location_code'),
                'lot' => $value($cols, $index, 'lot'),
                'counted_qty' => $counted,
                'reason' => $value($cols, $index, 'reason'),
            ];
        }
        fclose($handle);

        return $rows;
    }
}
