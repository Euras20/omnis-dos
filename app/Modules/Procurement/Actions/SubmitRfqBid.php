<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Models\Party;
use App\Models\Rfq;
use App\Models\RfqBid;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Outbox\OutboxWriter;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

final class SubmitRfqBid
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
    public function execute(string $rfqId, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'po.create');
        $companyId = $this->companyContext->companyId();
        $supplierId = (string) ($data['supplierId'] ?? '');
        if ($supplierId === '') {
            throw new BusinessRuleException('supplierId is required.');
        }

        return DB::transaction(function () use ($rfqId, $companyId, $actorId, $supplierId, $data) {
            $rfq = Rfq::query()
                ->where('company_id', $companyId)
                ->where('id', $rfqId)
                ->lockForUpdate()
                ->first();
            if ($rfq === null) {
                throw new NotFoundException('RFQ not found.');
            }
            if ($rfq->status !== 'open') {
                throw new BusinessRuleException("Bids are only accepted on open RFQs (status: {$rfq->status}).");
            }

            $supplier = Party::query()
                ->where('company_id', $companyId)
                ->where('id', $supplierId)
                ->where('party_type', 'supplier')
                ->where('is_active', true)
                ->first();
            if ($supplier === null) {
                throw new NotFoundException('Supplier not found.');
            }

            $exists = RfqBid::query()
                ->where('rfq_id', $rfqId)
                ->where('supplier_id', $supplierId)
                ->exists();
            if ($exists) {
                throw new ConflictException('This supplier already has a bid on this RFQ.');
            }

            $total = (int) ($data['totalMinor'] ?? 0);
            if ($total <= 0) {
                throw new BusinessRuleException('totalMinor must be positive.');
            }

            $bid = new RfqBid;
            $bid->fill([
                'id' => Uuid::v7(),
                'company_id' => $companyId,
                'rfq_id' => $rfqId,
                'supplier_id' => $supplierId,
                'bid_date' => (string) ($data['bidDate'] ?? date('Y-m-d')),
                'currency' => strtoupper((string) ($data['currency'] ?? 'GHS')),
                'total_minor' => $total,
                'status' => 'submitted',
                'line_prices' => $data['linePrices'] ?? null,
                'memo' => isset($data['memo']) ? trim((string) $data['memo']) : null,
                'created_by' => $actorId,
            ]);
            $bid->save();

            $this->auditWriter->write(
                action: 'rfq.bid_submitted',
                entityType: 'rfq_bid',
                entityId: $bid->id,
                detail: ['rfqId' => $rfqId, 'supplierId' => $supplierId, 'totalMinor' => $total],
                actorId: $actorId,
            );
            $this->outboxWriter->record([
                'aggregate_type' => 'rfq',
                'aggregate_id' => $rfqId,
                'event_type' => 'rfq.bid_submitted',
                'payload' => ['rfqId' => $rfqId, 'bidId' => $bid->id],
            ]);

            return [
                'id' => $bid->id,
                'rfqId' => $rfqId,
                'supplierId' => $supplierId,
                'totalMinor' => $total,
                'status' => $bid->status,
            ];
        });
    }
}
