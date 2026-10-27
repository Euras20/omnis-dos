<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\Item;
use App\Models\Location;
use App\Models\NumberSequence;
use App\Models\StockTransfer;
use App\Modules\Inventory\Domain\MovementRequest;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Services\MovementService;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/stock/transfers — move qty between locations (paired out/in movements).
 * MVP completes in one step (status = received), matching the review-build behaviour.
 */
final class PostStockTransfer
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly MovementService $movementService,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function executeBulk(array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'stock.move');

        $lines = $data['lines'] ?? null;
        if (! is_array($lines) || $lines === []) {
            throw new BusinessRuleException('lines must be a non-empty array.');
        }
        if (count($lines) > 100) {
            throw new BusinessRuleException('A bulk transfer accepts at most 100 lines.');
        }

        $defaults = [
            'fromLocationId' => $data['fromLocationId'] ?? null,
            'toLocationId' => $data['toLocationId'] ?? null,
            'transferDate' => $data['transferDate'] ?? $data['date'] ?? null,
            'memo' => $data['memo'] ?? null,
        ];

        return DB::transaction(function () use ($lines, $defaults) {
            $items = [];
            foreach ($lines as $index => $line) {
                if (! is_array($line)) {
                    throw new BusinessRuleException('Line '.($index + 1).' must be an object.');
                }
                $payload = array_merge(array_filter($defaults, static fn ($v) => $v !== null), $line);
                $items[] = $this->execute($payload);
            }

            return [
                'items' => $items,
                'total' => count($items),
            ];
        });
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
        $itemId = (string) ($data['itemId'] ?? '');
        $fromLocationId = (string) ($data['fromLocationId'] ?? '');
        $toLocationId = (string) ($data['toLocationId'] ?? '');
        $lot = isset($data['lot']) ? trim((string) $data['lot']) : null;
        if ($lot === '') {
            $lot = null;
        }
        $qty = $data['qty'] ?? null;
        $transferDate = (string) ($data['transferDate'] ?? $data['date'] ?? '');
        $memo = isset($data['memo']) ? trim((string) $data['memo']) : null;
        $requestId = $this->companyContext->requestId();

        if ($itemId === '' || $fromLocationId === '' || $toLocationId === '') {
            throw new BusinessRuleException('itemId, fromLocationId and toLocationId are required.');
        }
        if ($fromLocationId === $toLocationId) {
            throw new BusinessRuleException('fromLocationId and toLocationId must differ.');
        }
        if ($qty === null || ! is_numeric($qty) || (float) $qty <= 0) {
            throw new BusinessRuleException('qty must be a positive number.');
        }
        if ($transferDate === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $transferDate)) {
            throw new BusinessRuleException('transferDate must be YYYY-MM-DD.');
        }

        $qtyFloat = (float) $qty;
        $qtyStr = number_format($qtyFloat, 3, '.', '');

        return DB::transaction(function () use (
            $companyId,
            $itemId,
            $fromLocationId,
            $toLocationId,
            $lot,
            $qtyFloat,
            $qtyStr,
            $transferDate,
            $memo,
            $actorId,
            $requestId,
        ) {
            $item = Item::query()->where('company_id', $companyId)->where('id', $itemId)->where('is_active', true)->first();
            if ($item === null) {
                throw new BusinessRuleException('Item not found or inactive.');
            }
            foreach ([['from', $fromLocationId], ['to', $toLocationId]] as [$label, $locId]) {
                $loc = Location::query()->where('company_id', $companyId)->where('id', $locId)->where('is_active', true)->first();
                if ($loc === null) {
                    throw new BusinessRuleException("{$label} location not found or inactive.");
                }
            }

            $unitCost = $this->movementService->averageUnitCostMinor($companyId, $itemId, $fromLocationId, $lot);

            $transferId = Uuid::v7();
            $transferNo = $this->nextNumber($companyId, $transferDate, 'stock_transfer', 'TR');

            $outId = $this->movementService->record(new MovementRequest(
                companyId: $companyId,
                movementType: MovementType::TRANSFER_OUT,
                itemId: $itemId,
                locationId: $fromLocationId,
                lot: $lot,
                qty: number_format(-$qtyFloat, 3, '.', ''),
                unitCostMinor: $unitCost,
                movementDate: $transferDate,
                sourceType: 'stock_transfer',
                sourceId: $transferId,
                salesOrderLineId: null,
                goodsReceiptLineId: null,
                stockAssignmentId: null,
                journalId: null,
                memo: $memo ?? "Transfer {$transferNo} out",
                actorId: $actorId,
                requestId: $requestId,
            ));

            $inId = $this->movementService->record(new MovementRequest(
                companyId: $companyId,
                movementType: MovementType::TRANSFER_IN,
                itemId: $itemId,
                locationId: $toLocationId,
                lot: $lot,
                qty: $qtyStr,
                unitCostMinor: $unitCost,
                movementDate: $transferDate,
                sourceType: 'stock_transfer',
                sourceId: $transferId,
                salesOrderLineId: null,
                goodsReceiptLineId: null,
                stockAssignmentId: null,
                journalId: null,
                memo: $memo ?? "Transfer {$transferNo} in",
                actorId: $actorId,
                requestId: $requestId,
            ));

            $row = new StockTransfer;
            $row->id = $transferId;
            $row->company_id = $companyId;
            $row->transfer_no = $transferNo;
            $row->item_id = $itemId;
            $row->from_location_id = $fromLocationId;
            $row->to_location_id = $toLocationId;
            $row->lot = $lot;
            $row->qty = $qtyStr;
            $row->transfer_date = $transferDate;
            $row->status = 'received';
            $row->out_movement_id = $outId;
            $row->in_movement_id = $inId;
            $row->memo = $memo;
            $row->created_by = $actorId;
            $row->request_id = $requestId;
            $row->save();

            $this->auditWriter->write(
                action: 'stock_transfer.posted',
                entityType: 'stock_transfer',
                entityId: $transferId,
                detail: [
                    'transferNo' => $transferNo,
                    'itemId' => $itemId,
                    'qty' => $qtyFloat,
                    'fromLocationId' => $fromLocationId,
                    'toLocationId' => $toLocationId,
                    'lot' => $lot,
                ],
                actorId: $actorId,
            );

            return $this->toArray($row->fresh(['item', 'fromLocation', 'toLocation']));
        });
    }

    private function nextNumber(string $companyId, string $date, string $docType, string $prefix): string
    {
        $year = (int) substr($date, 0, 4);
        $seq = NumberSequence::query()
            ->where('company_id', $companyId)
            ->where('doc_type', $docType)
            ->where('fiscal_year', $year)
            ->lockForUpdate()
            ->first();

        if ($seq === null) {
            $seq = new NumberSequence;
            $seq->id = Uuid::v7();
            $seq->company_id = $companyId;
            $seq->doc_type = $docType;
            $seq->fiscal_year = $year;
            $seq->next_value = 1;
            $seq->prefix = $prefix;
            $seq->pad_length = 5;
            $seq->save();
            $seq = NumberSequence::query()->where('id', $seq->id)->lockForUpdate()->first();
        }

        $value = (int) $seq->next_value;
        $seq->next_value = $value + 1;
        $seq->save();

        $pad = max(1, (int) ($seq->pad_length ?? 5));

        return sprintf('%s-%d-%s', $seq->prefix ?: $prefix, $year, str_pad((string) $value, $pad, '0', STR_PAD_LEFT));
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(StockTransfer $t): array
    {
        return [
            'id' => $t->id,
            'transferNo' => $t->transfer_no,
            'itemId' => $t->item_id,
            'sku' => $t->item?->sku,
            'itemName' => $t->item?->name,
            'fromLocationId' => $t->from_location_id,
            'fromLocationCode' => $t->fromLocation?->code,
            'toLocationId' => $t->to_location_id,
            'toLocationCode' => $t->toLocation?->code,
            'lot' => $t->lot,
            'qty' => (float) $t->qty,
            'transferDate' => $t->transfer_date?->format('Y-m-d'),
            'status' => $t->status,
            'memo' => $t->memo,
            'createdAt' => $t->created_at?->toIso8601String(),
        ];
    }
}
