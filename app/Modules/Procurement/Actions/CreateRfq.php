<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\NumberSequence;
use App\Models\Rfq;
use App\Models\RfqLine;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Outbox\OutboxWriter;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

final class CreateRfq
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
    public function execute(array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'po.create');
        $companyId = $this->companyContext->companyId();
        $issueDate = (string) ($data['issueDate'] ?? date('Y-m-d'));
        $rawLines = $data['lines'] ?? [];
        if (! is_array($rawLines) || $rawLines === []) {
            throw new BusinessRuleException('At least one RFQ line is required.');
        }

        return DB::transaction(function () use ($companyId, $actorId, $issueDate, $rawLines, $data) {
            $id = Uuid::v7();
            $rfqNo = $this->nextNumber($companyId, $issueDate);
            $rfq = new Rfq;
            $rfq->fill([
                'id' => $id,
                'company_id' => $companyId,
                'rfq_no' => $rfqNo,
                'issue_date' => $issueDate,
                'due_date' => $data['dueDate'] ?? null,
                'status' => 'open',
                'created_by' => $actorId,
                'memo' => isset($data['memo']) ? trim((string) $data['memo']) : null,
                'request_id' => $this->companyContext->requestId(),
            ]);
            $rfq->save();

            foreach ($rawLines as $i => $raw) {
                if (! is_array($raw)) {
                    throw new BusinessRuleException("Line {$i} is invalid.");
                }
                $qty = (string) ($raw['qty'] ?? '');
                if ($qty === '' || ! is_numeric($qty) || (float) $qty <= 0) {
                    throw new BusinessRuleException("Line {$i}: qty must be positive.");
                }
                $desc = trim((string) ($raw['description'] ?? ''));
                if ($desc === '' && empty($raw['itemId'])) {
                    throw new BusinessRuleException("Line {$i}: description or itemId is required.");
                }
                $line = new RfqLine;
                $line->fill([
                    'id' => Uuid::v7(),
                    'company_id' => $companyId,
                    'rfq_id' => $id,
                    'line_no' => $i + 1,
                    'item_id' => $raw['itemId'] ?? null,
                    'description' => $desc !== '' ? $desc : 'Item',
                    'qty' => $qty,
                    'unit' => (string) ($raw['unit'] ?? 'pcs'),
                ]);
                $line->save();
            }

            $this->auditWriter->write(
                action: 'rfq.created',
                entityType: 'rfq',
                entityId: $id,
                detail: ['rfqNo' => $rfqNo],
                actorId: $actorId,
            );
            $this->outboxWriter->record([
                'aggregate_type' => 'rfq',
                'aggregate_id' => $id,
                'event_type' => 'rfq.created',
                'payload' => ['rfqId' => $id, 'rfqNo' => $rfqNo],
            ]);

            return self::toArray($rfq->fresh(['lines', 'bids']));
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(Rfq $rfq): array
    {
        return [
            'id' => $rfq->id,
            'rfqNo' => $rfq->rfq_no,
            'issueDate' => $rfq->issue_date?->format('Y-m-d'),
            'dueDate' => $rfq->due_date?->format('Y-m-d'),
            'status' => $rfq->status,
            'awardedBidId' => $rfq->awarded_bid_id,
            'awardedPurchaseOrderId' => $rfq->awarded_purchase_order_id,
            'memo' => $rfq->memo,
            'lines' => $rfq->lines->map(static fn (RfqLine $l) => [
                'id' => $l->id,
                'lineNo' => $l->line_no,
                'itemId' => $l->item_id,
                'description' => $l->description,
                'qty' => (string) $l->qty,
                'unit' => $l->unit,
            ])->all(),
            'bids' => $rfq->relationLoaded('bids')
                ? $rfq->bids->map(static fn ($b) => [
                    'id' => $b->id,
                    'supplierId' => $b->supplier_id,
                    'bidDate' => $b->bid_date?->format('Y-m-d'),
                    'totalMinor' => $b->total_minor,
                    'status' => $b->status,
                    'currency' => $b->currency,
                ])->all()
                : [],
        ];
    }

    private function nextNumber(string $companyId, string $date): string
    {
        $year = substr($date, 0, 4);
        $seq = NumberSequence::query()
            ->where('company_id', $companyId)
            ->where('doc_type', 'rfq')
            ->where('fiscal_year', $year)
            ->lockForUpdate()
            ->first();
        if ($seq === null) {
            $seq = new NumberSequence;
            $seq->id = Uuid::v7();
            $seq->company_id = $companyId;
            $seq->doc_type = 'rfq';
            $seq->fiscal_year = $year;
            $seq->next_value = 1;
            $seq->prefix = 'RFQ';
            $seq->save();
        }
        $value = $seq->next_value;
        $seq->next_value = $value + 1;
        $seq->save();

        return sprintf('RFQ-%s-%05d', $year, $value);
    }
}
