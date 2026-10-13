<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\Item;
use App\Models\Location;
use App\Models\NumberSequence;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;
// DB used for stock_on_hand snapshot

/**
 * POST /api/v1/stock/counts — open a cycle-count session (draft) with lines.
 * Book qty is snapshotted from stock_on_hand at create time when not supplied.
 */
final class CreateStockCount
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function execute(array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'stock.move');

        $companyId = $this->companyContext->companyId();
        $locationId = (string) ($data['locationId'] ?? '');
        $countDate = (string) ($data['countDate'] ?? $data['date'] ?? '');
        $rawLines = $data['lines'] ?? [];
        $memo = isset($data['memo']) ? trim((string) $data['memo']) : null;
        $requestId = $this->companyContext->requestId();

        if ($locationId === '') {
            throw new BusinessRuleException('locationId is required.');
        }
        if ($countDate === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $countDate)) {
            throw new BusinessRuleException('countDate must be YYYY-MM-DD.');
        }
        if (! is_array($rawLines) || $rawLines === []) {
            throw new BusinessRuleException('At least one count line is required.');
        }

        return DB::transaction(function () use (
            $companyId,
            $locationId,
            $countDate,
            $rawLines,
            $memo,
            $actorId,
            $requestId,
        ) {
            $location = Location::query()
                ->where('company_id', $companyId)
                ->where('id', $locationId)
                ->where('is_active', true)
                ->first();
            if ($location === null) {
                throw new BusinessRuleException('Location not found or inactive.');
            }

            $countId = Uuid::v7();
            $countNo = $this->nextNumber($companyId, $countDate);

            $header = new StockCount;
            $header->id = $countId;
            $header->company_id = $companyId;
            $header->count_no = $countNo;
            $header->location_id = $locationId;
            $header->count_date = $countDate;
            $header->status = 'draft';
            $header->memo = $memo;
            $header->created_by = $actorId;
            $header->request_id = $requestId;
            $header->save();

            $lineNo = 0;
            foreach ($rawLines as $raw) {
                if (! is_array($raw)) {
                    throw new BusinessRuleException('Each line must be an object.');
                }
                $itemId = (string) ($raw['itemId'] ?? '');
                if ($itemId === '') {
                    throw new BusinessRuleException('Each line requires itemId.');
                }
                $item = Item::query()->where('company_id', $companyId)->where('id', $itemId)->first();
                if ($item === null) {
                    throw new BusinessRuleException("Item {$itemId} not found.");
                }

                $lot = isset($raw['lot']) ? trim((string) $raw['lot']) : null;
                if ($lot === '') {
                    $lot = null;
                }

                $bookQty = array_key_exists('bookQty', $raw)
                    ? (float) $raw['bookQty']
                    : $this->bookQty($companyId, $itemId, $locationId, $lot);

                if (! array_key_exists('countedQty', $raw) || ! is_numeric($raw['countedQty'])) {
                    throw new BusinessRuleException('Each line requires countedQty.');
                }
                $countedQty = (float) $raw['countedQty'];
                $reason = isset($raw['reason']) ? trim((string) $raw['reason']) : null;

                $lineNo++;
                $line = new StockCountLine;
                $line->id = Uuid::v7();
                $line->company_id = $companyId;
                $line->stock_count_id = $countId;
                $line->line_no = $lineNo;
                $line->item_id = $itemId;
                $line->lot = $lot;
                $line->book_qty = number_format($bookQty, 3, '.', '');
                $line->counted_qty = number_format($countedQty, 3, '.', '');
                $line->reason = $reason;
                $line->save();
            }

            $this->auditWriter->write(
                action: 'stock_count.created',
                entityType: 'stock_count',
                entityId: $countId,
                detail: ['countNo' => $countNo, 'locationId' => $locationId, 'lineCount' => $lineNo],
                actorId: $actorId,
            );

            return $this->toArray(StockCount::query()->with(['lines.item', 'location'])->findOrFail($countId));
        });
    }

    private function bookQty(string $companyId, string $itemId, string $locationId, ?string $lot): float
    {
        $q = DB::table('stock_on_hand')
            ->where('company_id', $companyId)
            ->where('item_id', $itemId)
            ->where('location_id', $locationId);
        if ($lot === null) {
            $q->whereNull('lot');
        } else {
            $q->where('lot', $lot);
        }
        $row = $q->first();

        return $row !== null ? (float) $row->qty_on_hand : 0.0;
    }

    private function nextNumber(string $companyId, string $date): string
    {
        $year = (int) substr($date, 0, 4);
        $seq = NumberSequence::query()
            ->where('company_id', $companyId)
            ->where('doc_type', 'stock_count')
            ->where('fiscal_year', $year)
            ->lockForUpdate()
            ->first();

        if ($seq === null) {
            $seq = new NumberSequence;
            $seq->id = Uuid::v7();
            $seq->company_id = $companyId;
            $seq->doc_type = 'stock_count';
            $seq->fiscal_year = $year;
            $seq->next_value = 1;
            $seq->prefix = 'SC';
            $seq->pad_length = 5;
            $seq->save();
            $seq = NumberSequence::query()->where('id', $seq->id)->lockForUpdate()->first();
        }

        $value = (int) $seq->next_value;
        $seq->next_value = $value + 1;
        $seq->save();

        return sprintf('SC-%d-%05d', $year, $value);
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(StockCount $c): array
    {
        return [
            'id' => $c->id,
            'countNo' => $c->count_no,
            'locationId' => $c->location_id,
            'locationCode' => $c->location?->code,
            'countDate' => $c->count_date?->format('Y-m-d'),
            'status' => $c->status,
            'countedBy' => $c->counted_by,
            'countedAt' => $c->counted_at?->toIso8601String(),
            'approvedBy' => $c->approved_by,
            'approvedAt' => $c->approved_at?->toIso8601String(),
            'memo' => $c->memo,
            'lines' => $c->lines->map(static fn (StockCountLine $l) => [
                'id' => $l->id,
                'lineNo' => $l->line_no,
                'itemId' => $l->item_id,
                'sku' => $l->item?->sku,
                'lot' => $l->lot,
                'bookQty' => (float) $l->book_qty,
                'countedQty' => (float) $l->counted_qty,
                'varianceQty' => (float) $l->counted_qty - (float) $l->book_qty,
                'reason' => $l->reason,
            ])->values()->all(),
            'createdAt' => $c->created_at?->toIso8601String(),
        ];
    }
}
