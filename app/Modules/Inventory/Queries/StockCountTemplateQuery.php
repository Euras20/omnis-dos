<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Queries;

use App\Models\Item;
use App\Models\StockCountLine;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * CSV template for a stock count. Opens in Excel.
 *
 * Only items that still need a location or a counted quantity.
 * An item already on a count (location + counted qty) is left off.
 */
final class StockCountTemplateQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    public function csv(): string
    {
        $this->permissionChecker->assertCan($this->companyContext->userId(), 'stock.view');

        $companyId = $this->companyContext->companyId();

        $alreadyCounted = StockCountLine::query()
            ->where('stock_count_line.company_id', $companyId)
            ->whereNotNull('counted_qty')
            ->whereHas('stockCount', function ($query) use ($companyId) {
                $query->where('company_id', $companyId)
                    ->whereNotNull('location_id')
                    ->where('status', '!=', 'rejected');
            })
            ->pluck('item_id')
            ->unique()
            ->all();

        $items = Item::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->when($alreadyCounted !== [], fn ($query) => $query->whereNotIn('id', $alreadyCounted))
            ->orderBy('sku')
            ->get(['id', 'sku', 'name']);

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['item_id', 'sku', 'name', 'location_code', 'lot', 'counted_qty', 'reason']);

        foreach ($items as $item) {
            fputcsv($out, [
                $item->id,
                $item->sku,
                $item->name,
                '',
                '',
                '',
                '',
            ]);
        }

        rewind($out);
        $csv = stream_get_contents($out) ?: '';
        fclose($out);

        return $csv;
    }
}
