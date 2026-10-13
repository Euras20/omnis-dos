<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\NumberSequence;
use App\Models\Requisition;
use App\Models\RequisitionLine;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Outbox\OutboxWriter;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

final class CreateRequisition
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

        $requestDate = (string) ($data['requestDate'] ?? date('Y-m-d'));
        $rawLines = $data['lines'] ?? [];
        if (! is_array($rawLines) || $rawLines === []) {
            throw new BusinessRuleException('At least one requisition line is required.');
        }

        $submit = (bool) ($data['submit'] ?? true);

        return DB::transaction(function () use ($companyId, $actorId, $requestDate, $rawLines, $data, $submit) {
            $id = Uuid::v7();
            $reqNo = $this->nextNumber($companyId, $requestDate);

            $req = new Requisition;
            $req->fill([
                'id' => $id,
                'company_id' => $companyId,
                'req_no' => $reqNo,
                'requested_by' => $actorId,
                'request_date' => $requestDate,
                'status' => $submit ? 'pending' : 'draft',
                'preferred_supplier_id' => $data['preferredSupplierId'] ?? null,
                'memo' => isset($data['memo']) ? trim((string) $data['memo']) : null,
                'request_id' => $this->companyContext->requestId(),
            ]);
            $req->save();

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
                $line = new RequisitionLine;
                $line->fill([
                    'id' => Uuid::v7(),
                    'company_id' => $companyId,
                    'requisition_id' => $id,
                    'line_no' => $i + 1,
                    'item_id' => $raw['itemId'] ?? null,
                    'description' => $desc !== '' ? $desc : 'Item',
                    'qty' => $qty,
                    'unit' => (string) ($raw['unit'] ?? 'pcs'),
                    'estimated_unit_price_minor' => (int) ($raw['estimatedUnitPriceMinor'] ?? 0),
                ]);
                $line->save();
            }

            $this->auditWriter->write(
                action: 'requisition.created',
                entityType: 'requisition',
                entityId: $id,
                detail: ['reqNo' => $reqNo, 'status' => $req->status],
                actorId: $actorId,
            );

            $this->outboxWriter->record([
                'aggregate_type' => 'requisition',
                'aggregate_id' => $id,
                'event_type' => 'requisition.created',
                'payload' => ['requisitionId' => $id, 'reqNo' => $reqNo],
            ]);

            return $this->toArray($req->fresh('lines'));
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(Requisition $req): array
    {
        return [
            'id' => $req->id,
            'reqNo' => $req->req_no,
            'requestDate' => $req->request_date?->format('Y-m-d'),
            'status' => $req->status,
            'preferredSupplierId' => $req->preferred_supplier_id,
            'convertedPurchaseOrderId' => $req->converted_purchase_order_id,
            'memo' => $req->memo,
            'lines' => $req->lines->map(static fn (RequisitionLine $l) => [
                'id' => $l->id,
                'lineNo' => $l->line_no,
                'itemId' => $l->item_id,
                'description' => $l->description,
                'qty' => (string) $l->qty,
                'unit' => $l->unit,
                'estimatedUnitPriceMinor' => $l->estimated_unit_price_minor,
            ])->all(),
        ];
    }

    private function nextNumber(string $companyId, string $date): string
    {
        $year = substr($date, 0, 4);
        $seq = NumberSequence::query()
            ->where('company_id', $companyId)
            ->where('doc_type', 'requisition')
            ->where('fiscal_year', $year)
            ->lockForUpdate()
            ->first();
        if ($seq === null) {
            $seq = new NumberSequence;
            $seq->id = Uuid::v7();
            $seq->company_id = $companyId;
            $seq->doc_type = 'requisition';
            $seq->fiscal_year = $year;
            $seq->next_value = 1;
            $seq->prefix = 'REQ';
            $seq->save();
        }
        $value = $seq->next_value;
        $seq->next_value = $value + 1;
        $seq->save();

        return sprintf('REQ-%s-%05d', $year, $value);
    }
}
