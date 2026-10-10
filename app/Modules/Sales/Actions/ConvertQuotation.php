<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\NumberSequence;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Modules\Sales\Domain\QuotationStatus;
use App\Modules\Sales\Domain\SalesOrderStatus;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Outbox\OutboxWriter;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/quotations/{id}/convert — convert accepted quotation into a draft sales order.
 */
final class ConvertQuotation
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
    public function execute(string $id, array $data = []): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'sales.manage');

        $companyId = $this->companyContext->companyId();
        $requestId = $this->companyContext->requestId();
        $orderDate = (string) ($data['orderDate'] ?? date('Y-m-d'));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $orderDate)) {
            throw new BusinessRuleException('orderDate must be YYYY-MM-DD.');
        }

        return DB::transaction(function () use ($id, $companyId, $actorId, $requestId, $orderDate, $data) {
            $quotation = Quotation::query()
                ->with('lines')
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($quotation === null) {
                throw new NotFoundException('Quotation not found.');
            }

            if ($quotation->status === QuotationStatus::CONVERTED) {
                throw new BusinessRuleException('Quotation already converted.');
            }

            if ($quotation->status !== QuotationStatus::ACCEPTED) {
                throw new BusinessRuleException('Only accepted quotations can be converted.');
            }

            if ($quotation->lines->isEmpty()) {
                throw new BusinessRuleException('Quotation has no lines.');
            }

            $orderId = Uuid::v7();
            $orderNo = $this->nextNumber($companyId, $orderDate, 'sales_order');

            $order = new SalesOrder;
            $order->fill([
                'id' => $orderId,
                'company_id' => $companyId,
                'order_no' => $orderNo,
                'customer_id' => $quotation->customer_id,
                'quotation_id' => $quotation->id,
                'customer_po_ref' => isset($data['customerPoRef']) ? trim((string) $data['customerPoRef']) : null,
                'order_date' => $orderDate,
                'required_date' => $data['requiredDate'] ?? null,
                'currency' => $quotation->currency,
                'fx_rate' => $quotation->fx_rate,
                'status' => SalesOrderStatus::DRAFT,
                'net_minor' => $quotation->net_minor,
                'tax_total_minor' => $quotation->tax_total_minor,
                'gross_minor' => $quotation->gross_minor,
                'tax_rule_version_id' => $quotation->tax_rule_version_id,
                'tax_snapshot' => $quotation->tax_snapshot,
                'memo' => $quotation->memo,
                'deliver_to' => $data['deliverTo'] ?? null,
                'created_by' => $actorId,
                'request_id' => $requestId,
            ]);
            $order->save();

            foreach ($quotation->lines as $ql) {
                /** @var QuotationLine $ql */
                $sol = new SalesOrderLine;
                $sol->fill([
                    'id' => Uuid::v7(),
                    'company_id' => $companyId,
                    'sales_order_id' => $orderId,
                    'line_no' => $ql->line_no,
                    'item_id' => $ql->item_id,
                    'quotation_line_id' => $ql->id,
                    'description' => $ql->description,
                    'qty' => $ql->qty,
                    'unit' => $ql->unit,
                    'unit_price_minor' => $ql->unit_price_minor,
                    'net_minor' => $ql->net_minor,
                    'tax_minor' => $ql->tax_minor,
                    'gross_minor' => $ql->gross_minor,
                    'qty_delivered' => 0,
                    'qty_invoiced' => 0,
                ]);
                $sol->save();
            }

            $quotation->status = QuotationStatus::CONVERTED;
            $quotation->converted_sales_order_id = $orderId;
            $quotation->save();

            $this->auditWriter->write(
                action: 'quotation.converted',
                entityType: 'quotation',
                entityId: $quotation->id,
                detail: [
                    'quotationNo' => $quotation->quotation_no,
                    'salesOrderId' => $orderId,
                    'orderNo' => $orderNo,
                ],
                actorId: $actorId,
            );

            $this->outboxWriter->record([
                'aggregate_type' => 'sales_order',
                'aggregate_id' => $orderId,
                'event_type' => 'sales_order.created_from_quotation',
                'payload' => [
                    'salesOrderId' => $orderId,
                    'orderNo' => $orderNo,
                    'quotationId' => $quotation->id,
                ],
            ]);

            return [
                'id' => $orderId,
                'orderNo' => $orderNo,
                'status' => SalesOrderStatus::DRAFT,
                'quotationId' => $quotation->id,
                'customerId' => $order->customer_id,
                'grossMinor' => $order->gross_minor,
            ];
        });
    }

    private function nextNumber(string $companyId, string $date, string $docType): string
    {
        $year = substr($date, 0, 4);
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
            $seq->prefix = 'SO';
            $seq->save();
        }

        $value = $seq->next_value;
        $seq->next_value = $value + 1;
        $seq->save();

        $prefix = $seq->prefix ?: 'SO';

        return sprintf('%s-%s-%05d', $prefix, $year, $value);
    }
}
