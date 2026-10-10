<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\Account;
use App\Modules\Inventory\Domain\MovementRequest;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Services\MovementService;
use App\Modules\Ledger\Domain\PostingKey;
use App\Modules\Ledger\Domain\PostingLine;
use App\Modules\Ledger\Domain\PostingRequest;
use App\Modules\Ledger\Services\PostingService;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * Record a stock adjustment (cycle count write-on/write-off) with optional GL impact.
 *
 * Positive qty = write-on (Dr inventory, Cr adjustment income/expense).
 * Negative qty = write-off (Dr adjustment, Cr inventory).
 * Uses account 1300 (inventory) and 5900 (stock adjustment) by convention.
 *
 * Exposed for internal use and future cycle-count UI; not required by current API catalogue
 * but needed so Inventory can own all movement writes.
 */
final class RecordAdjustment
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly MovementService $movementService,
        private readonly PostingService $postingService,
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
        $itemId = (string) ($data['itemId'] ?? '');
        $locationId = (string) ($data['locationId'] ?? '');
        $lot = isset($data['lot']) ? trim((string) $data['lot']) : null;
        if ($lot === '') {
            $lot = null;
        }
        $qty = $data['qty'] ?? null;
        $unitCostMinor = isset($data['unitCostMinor']) ? (int) $data['unitCostMinor'] : null;
        $movementDate = (string) ($data['movementDate'] ?? $data['date'] ?? '');
        $memo = isset($data['memo']) ? trim((string) $data['memo']) : null;
        $postGl = (bool) ($data['postGl'] ?? true);
        $requestId = $this->companyContext->requestId();

        if ($itemId === '' || $locationId === '') {
            throw new BusinessRuleException('itemId and locationId are required.');
        }
        if ($qty === null || ! is_numeric($qty) || (float) $qty == 0.0) {
            throw new BusinessRuleException('qty must be a non-zero number.');
        }
        if ($movementDate === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $movementDate)) {
            throw new BusinessRuleException('movementDate must be YYYY-MM-DD.');
        }

        $qtyFloat = (float) $qty;
        $qtyStr = number_format($qtyFloat, 3, '.', '');

        return DB::transaction(function () use (
            $companyId,
            $itemId,
            $locationId,
            $lot,
            $qtyFloat,
            $qtyStr,
            $unitCostMinor,
            $movementDate,
            $memo,
            $postGl,
            $actorId,
            $requestId,
        ) {
            if ($unitCostMinor === null) {
                $unitCostMinor = $this->movementService->averageUnitCostMinor(
                    $companyId,
                    $itemId,
                    $locationId,
                    $lot,
                );
            }
            if ($unitCostMinor < 0) {
                throw new BusinessRuleException('unitCostMinor cannot be negative.');
            }

            $costTotal = (int) round(abs($qtyFloat) * $unitCostMinor);
            $adjustmentId = Uuid::v7();
            $journalId = null;

            if ($postGl && $costTotal > 0) {
                $inventory = $this->accountByCode($companyId, '1300');
                $adjAccount = $this->accountByCode($companyId, '5900');

                $lines = $qtyFloat > 0
                    ? [
                        new PostingLine($inventory->id, $costTotal, 0, null, 'Stock write-on'),
                        new PostingLine($adjAccount->id, 0, $costTotal, null, 'Stock adjustment'),
                    ]
                    : [
                        new PostingLine($adjAccount->id, $costTotal, 0, null, 'Stock write-off'),
                        new PostingLine($inventory->id, 0, $costTotal, null, 'Inventory reduction'),
                    ];

                $journalId = $this->postingService->post(new PostingRequest(
                    companyId: $companyId,
                    postingDate: $movementDate,
                    postingKey: PostingKey::make('stock_adjustment', $adjustmentId, 'adjust'),
                    sourceType: 'stock_adjustment',
                    sourceId: $adjustmentId,
                    eventType: 'adjust',
                    description: $memo ?? "Stock adjustment {$adjustmentId}",
                    lines: $lines,
                    actorId: $actorId,
                    requestId: $requestId,
                    preparedBy: $actorId,
                ));

            }

            $movementId = $this->movementService->record(new MovementRequest(
                companyId: $companyId,
                movementType: MovementType::ADJUSTMENT,
                itemId: $itemId,
                locationId: $locationId,
                lot: $lot,
                qty: $qtyStr,
                unitCostMinor: $unitCostMinor,
                movementDate: $movementDate,
                sourceType: 'stock_adjustment',
                sourceId: $adjustmentId,
                salesOrderLineId: null,
                goodsReceiptLineId: null,
                stockAssignmentId: null,
                journalId: $journalId,
                memo: $memo,
                actorId: $actorId,
                requestId: $requestId,
            ));

            $this->auditWriter->write(
                action: 'stock.adjustment.record',
                entityType: 'stock_adjustment',
                entityId: $adjustmentId,
                detail: [
                    'itemId' => $itemId,
                    'locationId' => $locationId,
                    'lot' => $lot,
                    'qty' => $qtyStr,
                    'unitCostMinor' => $unitCostMinor,
                    'journalId' => $journalId,
                    'movementId' => $movementId,
                ],
            );

            return [
                'id' => $adjustmentId,
                'itemId' => $itemId,
                'locationId' => $locationId,
                'lot' => $lot,
                'qty' => $qtyStr,
                'unitCostMinor' => $unitCostMinor,
                'movementDate' => $movementDate,
                'journalId' => $journalId,
                'movementId' => $movementId,
            ];
        });
    }

    private function accountByCode(string $companyId, string $code): Account
    {
        $account = Account::query()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if ($account === null) {
            throw new BusinessRuleException("Required account code {$code} is missing or inactive.");
        }

        return $account;
    }
}
