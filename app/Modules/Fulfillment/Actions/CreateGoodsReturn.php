<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Account;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\GoodsReturn;
use App\Models\Location;
use App\Models\NumberSequence;
use App\Models\StockMovement;
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
 * POST /api/v1/returns — create a goods return.
 *
 * Customer: from a delivered delivery line. Stock into quarantine, reverse COGS.
 * Supplier: from quarantine. No journal until dispatch.
 * Does not reopen sales demand and does not post a credit note.
 */
final class CreateGoodsReturn
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
        if (! $this->permissionChecker->can($actorId, 'delivery.return')
            && ! $this->permissionChecker->can($actorId, 'stock.move')) {
            $this->permissionChecker->assertCan($actorId, 'delivery.return');
        }

        $companyId = $this->companyContext->companyId();
        $kind = strtolower(trim((string) ($data['kind'] ?? 'customer')));
        $reason = trim((string) ($data['reason'] ?? ''));
        $qty = (float) ($data['qty'] ?? 0);
        $returnDate = (string) ($data['returnDate'] ?? now()->toDateString());

        if (! in_array($kind, ['customer', 'supplier'], true)) {
            throw new BusinessRuleException('kind must be customer or supplier.');
        }
        if ($reason === '') {
            throw new BusinessRuleException('reason is required.');
        }
        if ($qty <= 0) {
            throw new BusinessRuleException('qty must be positive.');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate)) {
            throw new BusinessRuleException('returnDate must be YYYY-MM-DD.');
        }

        return DB::transaction(function () use ($companyId, $actorId, $kind, $reason, $qty, $returnDate, $data) {
            if ($kind === 'customer') {
                return $this->createCustomer($companyId, $actorId, $reason, $qty, $returnDate, $data);
            }

            return $this->createSupplier($companyId, $actorId, $reason, $qty, $returnDate, $data);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function createCustomer(
        string $companyId,
        ?string $actorId,
        string $reason,
        float $qty,
        string $returnDate,
        array $data,
    ): array {
        $lineId = (string) ($data['deliveryNoteLineId'] ?? '');
        if ($lineId === '') {
            throw new BusinessRuleException('deliveryNoteLineId is required for a customer return.');
        }

        $line = DeliveryNoteLine::query()
            ->where('company_id', $companyId)
            ->where('id', $lineId)
            ->lockForUpdate()
            ->first();
        if ($line === null) {
            throw new NotFoundException('Delivery line not found.');
        }

        $note = DeliveryNote::query()
            ->where('company_id', $companyId)
            ->where('id', $line->delivery_note_id)
            ->first();
        if ($note === null || $note->status !== 'delivered') {
            throw new BusinessRuleException('Only a delivered delivery line can be returned.');
        }

        $accepted = (float) ($line->accepted_qty ?? $line->qty);
        $already = (float) GoodsReturn::query()
            ->where('company_id', $companyId)
            ->where('delivery_note_line_id', $line->id)
            ->whereNotIn('status', ['cancelled'])
            ->sum('qty');
        $remaining = $accepted - $already;
        if ($qty > $remaining + 0.0005) {
            throw new BusinessRuleException(
                "Cannot return {$qty}; only {$remaining} remains on this delivery line."
            );
        }

        $quarantine = $this->quarantineLocation($companyId, $data['locationId'] ?? null);
        $unitCost = $this->deliveryUnitCost($companyId, $note->id, $line);
        $costTotal = (int) round($qty * $unitCost);

        $return = $this->persist(
            companyId: $companyId,
            actorId: $actorId,
            kind: 'customer',
            status: 'awaiting_inspection',
            reason: $reason,
            qty: $qty,
            returnDate: $returnDate,
            itemId: (string) $line->item_id,
            locationId: (string) $quarantine->id,
            lot: $line->lot,
            unitCost: $unitCost,
            salesOrderId: $note->sales_order_id,
            salesOrderLineId: $line->sales_order_line_id,
            deliveryNoteId: $note->id,
            deliveryNoteLineId: $line->id,
            partyId: null,
        );

        $this->movementService->record(new MovementRequest(
            companyId: $companyId,
            movementType: MovementType::RETURN,
            itemId: (string) $line->item_id,
            locationId: (string) $quarantine->id,
            lot: $line->lot,
            qty: number_format($qty, 3, '.', ''),
            unitCostMinor: $unitCost,
            movementDate: $returnDate,
            sourceType: 'goods_return',
            sourceId: $return->id,
            salesOrderLineId: $line->sales_order_line_id,
            goodsReceiptLineId: null,
            stockAssignmentId: null,
            journalId: null,
            memo: "Customer return {$return->return_no}",
            actorId: (string) $actorId,
            requestId: $this->companyContext->requestId(),
        ));

        if ($costTotal > 0) {
            $inventory = $this->accountByCode($companyId, '1300');
            $cogs = $this->accountByCode($companyId, '5000');
            $journalId = $this->postingService->post(new PostingRequest(
                companyId: $companyId,
                postingDate: $returnDate,
                postingKey: PostingKey::make('goods_return', $return->id, 'receive'),
                sourceType: 'goods_return',
                sourceId: $return->id,
                eventType: 'receive',
                description: "Customer return {$return->return_no}: reverse cost of sales",
                lines: [
                    new PostingLine(accountId: $inventory->id, debitMinor: $costTotal, creditMinor: 0, memo: $return->return_no),
                    new PostingLine(accountId: $cogs->id, debitMinor: 0, creditMinor: $costTotal, memo: $return->return_no),
                ],
                actorId: (string) $actorId,
                requestId: $this->companyContext->requestId(),
            ));
            $return->journal_id = $journalId;
            $return->save();
        }

        $this->auditWriter->write(
            action: 'goods_return.created',
            entityType: 'goods_return',
            entityId: $return->id,
            detail: ['returnNo' => $return->return_no, 'kind' => 'customer', 'qty' => $qty],
            actorId: $actorId,
        );

        return $this->toArray($return);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function createSupplier(
        string $companyId,
        ?string $actorId,
        string $reason,
        float $qty,
        string $returnDate,
        array $data,
    ): array {
        $itemId = (string) ($data['itemId'] ?? '');
        $locationId = (string) ($data['locationId'] ?? '');
        if ($itemId === '' || $locationId === '') {
            throw new BusinessRuleException('itemId and locationId are required for a supplier return.');
        }

        $location = Location::query()
            ->where('company_id', $companyId)
            ->where('id', $locationId)
            ->where('is_active', true)
            ->first();
        if ($location === null) {
            throw new NotFoundException('Location not found.');
        }

        $unitCost = $this->movementService->averageUnitCostMinor(
            $companyId,
            $itemId,
            $locationId,
            isset($data['lot']) ? (string) $data['lot'] : null,
        );

        $return = $this->persist(
            companyId: $companyId,
            actorId: $actorId,
            kind: 'supplier',
            status: 'awaiting_dispatch',
            reason: $reason,
            qty: $qty,
            returnDate: $returnDate,
            itemId: $itemId,
            locationId: $locationId,
            lot: isset($data['lot']) ? (string) $data['lot'] : null,
            unitCost: $unitCost,
            salesOrderId: null,
            salesOrderLineId: null,
            deliveryNoteId: null,
            deliveryNoteLineId: null,
            partyId: isset($data['partyId']) ? (string) $data['partyId'] : null,
        );

        $this->auditWriter->write(
            action: 'goods_return.created',
            entityType: 'goods_return',
            entityId: $return->id,
            detail: ['returnNo' => $return->return_no, 'kind' => 'supplier', 'qty' => $qty],
            actorId: $actorId,
        );

        return $this->toArray($return);
    }

    private function persist(
        string $companyId,
        ?string $actorId,
        string $kind,
        string $status,
        string $reason,
        float $qty,
        string $returnDate,
        string $itemId,
        string $locationId,
        ?string $lot,
        int $unitCost,
        ?string $salesOrderId,
        ?string $salesOrderLineId,
        ?string $deliveryNoteId,
        ?string $deliveryNoteLineId,
        ?string $partyId,
    ): GoodsReturn {
        $return = new GoodsReturn;
        $return->id = Uuid::v7();
        $return->company_id = $companyId;
        $return->return_no = $this->nextNumber($companyId, $returnDate);
        $return->kind = $kind;
        $return->status = $status;
        $return->sales_order_id = $salesOrderId;
        $return->sales_order_line_id = $salesOrderLineId;
        $return->delivery_note_id = $deliveryNoteId;
        $return->delivery_note_line_id = $deliveryNoteLineId;
        $return->party_id = $partyId;
        $return->item_id = $itemId;
        $return->location_id = $locationId;
        $return->lot = $lot;
        $return->qty = number_format($qty, 3, '.', '');
        $return->unit_cost_minor = $unitCost;
        $return->reason = $reason;
        $return->return_date = $returnDate;
        $return->created_by = $actorId;
        $return->request_id = $this->companyContext->requestId();
        $return->save();

        return $return;
    }

    private function quarantineLocation(string $companyId, mixed $requested): Location
    {
        if (is_string($requested) && $requested !== '') {
            $location = Location::query()
                ->where('company_id', $companyId)
                ->where('id', $requested)
                ->where('is_active', true)
                ->first();
            if ($location === null) {
                throw new NotFoundException('Quarantine location not found.');
            }

            return $location;
        }

        $location = Location::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('loc_type', 'quarantine')
            ->first();
        if ($location === null) {
            throw new BusinessRuleException('No quarantine location is configured. Pass locationId.');
        }

        return $location;
    }

    private function deliveryUnitCost(string $companyId, string $deliveryNoteId, DeliveryNoteLine $line): int
    {
        $movement = StockMovement::query()
            ->where('company_id', $companyId)
            ->where('source_type', 'delivery_note')
            ->where('source_id', $deliveryNoteId)
            ->where('item_id', $line->item_id)
            ->where('movement_type', MovementType::DELIVERY)
            ->first();

        if ($movement !== null) {
            return (int) $movement->unit_cost_minor;
        }

        return $this->movementService->averageUnitCostMinor(
            $companyId,
            (string) $line->item_id,
            (string) $line->location_id,
            $line->lot,
        );
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

    private function nextNumber(string $companyId, string $date): string
    {
        $year = (int) substr($date, 0, 4);
        $seq = NumberSequence::query()
            ->where('company_id', $companyId)
            ->where('doc_type', 'goods_return')
            ->where('fiscal_year', $year)
            ->lockForUpdate()
            ->first();

        if ($seq === null) {
            $seq = new NumberSequence;
            $seq->id = Uuid::v7();
            $seq->company_id = $companyId;
            $seq->doc_type = 'goods_return';
            $seq->fiscal_year = $year;
            $seq->next_value = 1;
            $seq->prefix = 'RMA';
            $seq->pad_length = 5;
            $seq->save();
            $seq = NumberSequence::query()->where('id', $seq->id)->lockForUpdate()->first();
        }

        $value = (int) $seq->next_value;
        $seq->next_value = $value + 1;
        $seq->save();

        return sprintf('RMA-%d-%s', $year, str_pad((string) $value, 5, '0', STR_PAD_LEFT));
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(GoodsReturn $return): array
    {
        return [
            'id' => $return->id,
            'returnNo' => $return->return_no,
            'kind' => $return->kind,
            'status' => $return->status,
            'salesOrderId' => $return->sales_order_id,
            'salesOrderLineId' => $return->sales_order_line_id,
            'deliveryNoteId' => $return->delivery_note_id,
            'deliveryNoteLineId' => $return->delivery_note_line_id,
            'partyId' => $return->party_id,
            'itemId' => $return->item_id,
            'locationId' => $return->location_id,
            'lot' => $return->lot,
            'qty' => (float) $return->qty,
            'reason' => $return->reason,
            'returnDate' => $return->return_date?->format('Y-m-d'),
            'journalId' => $return->journal_id,
        ];
    }
}
