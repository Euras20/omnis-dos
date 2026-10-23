<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Item;
use App\Models\NumberSequence;
use App\Models\Party;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\TaxRuleVersion;
use App\Modules\Sales\Domain\SalesOrderStatus;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Outbox\OutboxWriter;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/sales-orders — create a sales order (customer PO reference optional).
 */
final class CreateSalesOrder
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
        $this->permissionChecker->assertCan($actorId, 'sales.manage');

        $companyId = $this->companyContext->companyId();
        $customerId = (string) ($data['customerId'] ?? '');
        $orderDate = (string) ($data['orderDate'] ?? $data['date'] ?? '');
        $rawLines = $data['lines'] ?? [];

        if ($customerId === '') {
            throw new BusinessRuleException('customerId is required.');
        }
        if ($orderDate === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $orderDate)) {
            throw new BusinessRuleException('orderDate must be YYYY-MM-DD.');
        }
        if (! is_array($rawLines) || $rawLines === []) {
            throw new BusinessRuleException('At least one sales order line is required.');
        }

        $customer = Party::query()
            ->where('company_id', $companyId)
            ->where('id', $customerId)
            ->where('party_type', 'customer')
            ->where('is_active', true)
            ->first();
        if ($customer === null) {
            throw new NotFoundException('Customer not found.');
        }

        $currency = strtoupper((string) ($data['currency'] ?? $customer->currency ?? 'GHS'));
        $customerPoRef = isset($data['customerPoRef']) ? trim((string) $data['customerPoRef']) : null;
        if ($customerPoRef === '') {
            $customerPoRef = null;
        }
        $memo = isset($data['memo']) ? trim((string) $data['memo']) : null;
        $deliverTo = isset($data['deliverTo']) ? trim((string) $data['deliverTo']) : null;
        $requiredDate = isset($data['requiredDate']) ? (string) $data['requiredDate'] : null;
        $orderTypeId = isset($data['orderTypeId']) ? (string) $data['orderTypeId'] : null;
        $quotationId = isset($data['quotationId']) ? (string) $data['quotationId'] : null;
        $requestId = $this->companyContext->requestId();
        $taxRates = $this->resolveTaxRates($companyId, $orderDate);
        $combinedTaxRate = $taxRates['vat'] + $taxRates['nhil'] + $taxRates['getfund'];

        return DB::transaction(function () use (
            $companyId,
            $customerId,
            $orderDate,
            $requiredDate,
            $rawLines,
            $currency,
            $customerPoRef,
            $memo,
            $deliverTo,
            $orderTypeId,
            $quotationId,
            $actorId,
            $requestId,
            $combinedTaxRate,
            $taxRates,
        ) {
            $orderId = Uuid::v7();
            $orderNo = $this->nextNumber($companyId, $orderDate, 'sales_order');

            $headerNet = 0;
            $headerTax = 0;
            $lineRows = [];

            foreach ($rawLines as $index => $raw) {
                if (! is_array($raw)) {
                    throw new BusinessRuleException("Line {$index} is invalid.");
                }
                $itemId = isset($raw['itemId']) ? (string) $raw['itemId'] : null;
                $description = trim((string) ($raw['description'] ?? ''));
                $qty = (string) ($raw['qty'] ?? '');
                $unitPrice = (int) ($raw['unitPriceMinor'] ?? $raw['unit_price_minor'] ?? 0);
                $unit = (string) ($raw['unit'] ?? 'pcs');

                if ($qty === '' || ! is_numeric($qty) || (float) $qty <= 0) {
                    throw new BusinessRuleException("Line {$index}: qty must be positive.");
                }
                if ($unitPrice < 0) {
                    throw new BusinessRuleException("Line {$index}: unitPriceMinor cannot be negative.");
                }

                if ($itemId !== null && $itemId !== '') {
                    $item = Item::query()
                        ->where('company_id', $companyId)
                        ->where('id', $itemId)
                        ->where('is_active', true)
                        ->first();
                    if ($item === null) {
                        throw new NotFoundException("Item not found for line {$index}.");
                    }
                    if ($description === '') {
                        $description = (string) $item->name;
                    }
                } elseif ($description === '') {
                    throw new BusinessRuleException("Line {$index}: description is required when itemId is omitted.");
                }

                $net = (int) round((float) $qty * $unitPrice);
                $tax = (int) round($net * $combinedTaxRate);
                $gross = $net + $tax;
                $headerNet += $net;
                $headerTax += $tax;

                $lineRows[] = [
                    'id' => Uuid::v7(),
                    'company_id' => $companyId,
                    'sales_order_id' => $orderId,
                    'line_no' => $index + 1,
                    'item_id' => $itemId ?: null,
                    'description' => $description,
                    'qty' => $qty,
                    'unit' => $unit,
                    'unit_price_minor' => $unitPrice,
                    'net_minor' => $net,
                    'tax_minor' => $tax,
                    'gross_minor' => $gross,
                    'qty_delivered' => 0,
                    'qty_invoiced' => 0,
                ];
            }

            $order = new SalesOrder;
            $order->fill([
                'id' => $orderId,
                'company_id' => $companyId,
                'order_no' => $orderNo,
                'customer_id' => $customerId,
                'quotation_id' => $quotationId,
                'customer_po_ref' => $customerPoRef,
                'order_type_id' => $orderTypeId,
                'order_date' => $orderDate,
                'required_date' => $requiredDate,
                'currency' => $currency,
                'status' => SalesOrderStatus::DRAFT,
                'net_minor' => $headerNet,
                'tax_total_minor' => $headerTax,
                'gross_minor' => $headerNet + $headerTax,
                'tax_snapshot' => $taxRates,
                'memo' => $memo,
                'deliver_to' => $deliverTo,
                'created_by' => $actorId,
                'request_id' => $requestId,
            ]);
            $order->save();

            foreach ($lineRows as $row) {
                $line = new SalesOrderLine;
                $line->fill($row);
                $line->save();
            }

            $this->auditWriter->write(
                action: 'sales_order.created',
                entityType: 'sales_order',
                entityId: $orderId,
                detail: [
                    'orderNo' => $orderNo,
                    'customerId' => $customerId,
                    'grossMinor' => $headerNet + $headerTax,
                    'lineCount' => count($lineRows),
                ],
                actorId: $actorId,
            );

            $this->outboxWriter->record([
                'aggregate_type' => 'sales_order',
                'aggregate_id' => $orderId,
                'event_type' => 'sales_order.created',
                'payload' => [
                    'salesOrderId' => $orderId,
                    'orderNo' => $orderNo,
                    'customerId' => $customerId,
                ],
            ]);

            return $this->toArray(SalesOrder::query()->with('lines')->findOrFail($orderId));
        });
    }

    /**
     * @return array{vat: float, nhil: float, getfund: float}
     */
    private function resolveTaxRates(string $companyId, string $date): array
    {
        $defaults = ['vat' => 0.15, 'nhil' => 0.025, 'getfund' => 0.025];
        $rule = TaxRuleVersion::query()
            ->where('company_id', $companyId)
            ->where('effective_from', '<=', $date)
            ->where('is_active', true)
            ->orderByDesc('effective_from')
            ->first();

        if ($rule === null) {
            return $defaults;
        }

        $components = is_array($rule->components) ? $rule->components : [];
        $pick = static function (array $c, string $key, float $fallback): float {
            if (isset($c[$key]) && is_numeric($c[$key])) {
                $v = (float) $c[$key];

                return $v > 1 ? $v / 100.0 : $v;
            }

            return $fallback;
        };

        // COVID levy is no longer applicable. Omit it from the snapshot.
        return [
            'vat' => $pick($components, 'vat', $defaults['vat']),
            'nhil' => $pick($components, 'nhil', $defaults['nhil']),
            'getfund' => $pick($components, 'getfund', $defaults['getfund']),
        ];
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

    /**
     * @return array<string, mixed>
     */
    private function toArray(SalesOrder $order): array
    {
        return [
            'id' => $order->id,
            'orderNo' => $order->order_no,
            'customerId' => $order->customer_id,
            'quotationId' => $order->quotation_id,
            'customerPoRef' => $order->customer_po_ref,
            'orderDate' => $order->order_date?->format('Y-m-d'),
            'requiredDate' => $order->required_date?->format('Y-m-d'),
            'currency' => $order->currency,
            'status' => $order->status,
            'netMinor' => $order->net_minor,
            'taxTotalMinor' => $order->tax_total_minor,
            'grossMinor' => $order->gross_minor,
            'memo' => $order->memo,
            'deliverTo' => $order->deliver_to,
            'lines' => $order->lines->map(static fn (SalesOrderLine $l) => [
                'id' => $l->id,
                'lineNo' => $l->line_no,
                'itemId' => $l->item_id,
                'description' => $l->description,
                'qty' => (string) $l->qty,
                'unit' => $l->unit,
                'unitPriceMinor' => $l->unit_price_minor,
                'netMinor' => $l->net_minor,
                'taxMinor' => $l->tax_minor,
                'grossMinor' => $l->gross_minor,
                'qtyDelivered' => (string) $l->qty_delivered,
                'qtyInvoiced' => (string) $l->qty_invoiced,
            ])->all(),
            'createdAt' => $order->created_at?->toIso8601String(),
        ];
    }
}
