<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Models\Rfq;
use App\Models\RfqBid;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Outbox\OutboxWriter;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * Awards an RFQ to a bid and creates a draft PO via CreatePurchaseOrder (single PO path).
 */
final class AwardRfq
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly CreatePurchaseOrder $createPurchaseOrder,
        private readonly AuditWriter $auditWriter,
        private readonly OutboxWriter $outboxWriter,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data  bidId required
     * @return array<string, mixed>
     */
    public function execute(string $rfqId, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'po.approve');
        $companyId = $this->companyContext->companyId();
        $bidId = (string) ($data['bidId'] ?? '');
        if ($bidId === '') {
            throw new BusinessRuleException('bidId is required.');
        }

        return DB::transaction(function () use ($rfqId, $companyId, $actorId, $bidId, $data) {
            $rfq = Rfq::query()
                ->with('lines')
                ->where('company_id', $companyId)
                ->where('id', $rfqId)
                ->lockForUpdate()
                ->first();
            if ($rfq === null) {
                throw new NotFoundException('RFQ not found.');
            }
            if ($rfq->status === 'awarded' && $rfq->awarded_purchase_order_id) {
                throw new ConflictException('RFQ already awarded to purchase order '.$rfq->awarded_purchase_order_id);
            }
            if ($rfq->status !== 'open') {
                throw new BusinessRuleException("Only open RFQs can be awarded (status: {$rfq->status}).");
            }

            $bid = RfqBid::query()
                ->where('company_id', $companyId)
                ->where('rfq_id', $rfqId)
                ->where('id', $bidId)
                ->lockForUpdate()
                ->first();
            if ($bid === null) {
                throw new NotFoundException('Bid not found on this RFQ.');
            }
            if ($bid->status !== 'submitted') {
                throw new BusinessRuleException("Bid cannot be awarded (status: {$bid->status}).");
            }

            $priceByLine = [];
            if (is_array($bid->line_prices)) {
                foreach ($bid->line_prices as $lp) {
                    if (is_array($lp) && isset($lp['rfqLineId'])) {
                        $priceByLine[(string) $lp['rfqLineId']] = (int) ($lp['unitPriceMinor'] ?? 0);
                    }
                }
            }

            $lines = [];
            foreach ($rfq->lines as $line) {
                $unitPrice = $priceByLine[$line->id] ?? 0;
                if ($unitPrice <= 0 && count($rfq->lines) === 1) {
                    // Single-line RFQ: derive unit price from bid total.
                    $unitPrice = (int) round($bid->total_minor / max(1, (float) $line->qty));
                }
                $lines[] = [
                    'itemId' => $line->item_id,
                    'description' => $line->description,
                    'qty' => (string) $line->qty,
                    'unit' => $line->unit,
                    'unitPriceMinor' => $unitPrice,
                ];
            }

            $po = $this->createPurchaseOrder->execute([
                'supplierId' => $bid->supplier_id,
                'orderDate' => (string) ($data['orderDate'] ?? date('Y-m-d')),
                'currency' => $bid->currency,
                'memo' => 'Awarded from RFQ '.$rfq->rfq_no,
                'lines' => $lines,
            ]);

            $bid->status = 'awarded';
            $bid->save();

            RfqBid::query()
                ->where('rfq_id', $rfqId)
                ->where('id', '!=', $bidId)
                ->where('status', 'submitted')
                ->update(['status' => 'rejected']);

            $rfq->status = 'awarded';
            $rfq->awarded_bid_id = $bid->id;
            $rfq->awarded_purchase_order_id = $po['id'];
            $rfq->save();

            $this->auditWriter->write(
                action: 'rfq.awarded',
                entityType: 'rfq',
                entityId: $rfq->id,
                detail: [
                    'rfqNo' => $rfq->rfq_no,
                    'bidId' => $bid->id,
                    'purchaseOrderId' => $po['id'],
                ],
                actorId: $actorId,
            );
            $this->outboxWriter->record([
                'aggregate_type' => 'rfq',
                'aggregate_id' => $rfq->id,
                'event_type' => 'rfq.awarded',
                'payload' => [
                    'rfqId' => $rfq->id,
                    'bidId' => $bid->id,
                    'purchaseOrderId' => $po['id'],
                ],
            ]);

            return [
                'id' => $rfq->id,
                'rfqNo' => $rfq->rfq_no,
                'status' => $rfq->status,
                'awardedBidId' => $bid->id,
                'purchaseOrderId' => $po['id'],
                'poNo' => $po['poNo'] ?? null,
            ];
        });
    }
}
