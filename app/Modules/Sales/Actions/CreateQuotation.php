<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Item;
use App\Models\NumberSequence;
use App\Models\Party;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\TaxRuleVersion;
use App\Modules\Sales\Domain\QuotationStatus;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Outbox\OutboxWriter;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/quotations — create a quotation (any number of lines).
 */
final class CreateQuotation
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
        $this->permissionChecker->assertCan($actorId, 'sales.quotes.manage');

        $companyId = $this->companyContext->companyId();
        $customerId = (string) ($data['customerId'] ?? '');
        $quotationDate = (string) ($data['quotationDate'] ?? $data['date'] ?? '');
        $rawLines = $data['lines'] ?? [];

        if ($customerId === '') {
            throw new BusinessRuleException('customerId is required.');
        }
        if ($quotationDate === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $quotationDate)) {
            throw new BusinessRuleException('quotationDate must be YYYY-MM-DD.');
        }
        if (! is_array($rawLines) || $rawLines === []) {
            throw new BusinessRuleException('At least one quotation line is required.');
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
        $discountPercent = isset($data['discountPercent']) ? (float) $data['discountPercent'] : 0.0;
        if ($discountPercent < 0 || $discountPercent > 100) {
            throw new BusinessRuleException('discountPercent must be between 0 and 100.');
        }
        $validUntil = isset($data['validUntil']) ? (string) $data['validUntil'] : null;
        $memo = isset($data['memo']) ? trim((string) $data['memo']) : null;
        $requestId = $this->companyContext->requestId();
        $taxRates = $this->resolveTaxRates($companyId, $quotationDate);
        $combinedTaxRate = $taxRates['vat'] + $taxRates['nhil'] + $taxRates['getfund'] + $taxRates['covid'];

        return DB::transaction(function () use (
            $companyId,
            $customerId,
            $quotationDate,
            $validUntil,
            $rawLines,
            $currency,
            $discountPercent,
            $memo,
            $actorId,
            $requestId,
            $combinedTaxRate,
            $taxRates,
        ) {
            $quotationId = Uuid::v7();
            $quotationNo = $this->nextNumber($companyId, $quotationDate, 'quotation');

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
                    'quotation_id' => $quotationId,
                    'line_no' => $index + 1,
                    'item_id' => $itemId ?: null,
                    'description' => $description,
                    'qty' => $qty,
                    'unit' => $unit,
                    'unit_price_minor' => $unitPrice,
                    'net_minor' => $net,
                    'tax_minor' => $tax,
                    'gross_minor' => $gross,
                ];
            }

            $discountedNet = (int) round($headerNet * (1 - $discountPercent / 100));
            $discountedTax = (int) round($discountedNet * $combinedTaxRate);
            $discountedGross = $discountedNet + $discountedTax;

            $quotation = new Quotation;
            $quotation->fill([
                'id' => $quotationId,
                'company_id' => $companyId,
                'quotation_no' => $quotationNo,
                'customer_id' => $customerId,
                'quotation_date' => $quotationDate,
                'valid_until' => $validUntil,
                'currency' => $currency,
                'discount_percent' => $discountPercent,
                'status' => QuotationStatus::DRAFT,
                'net_minor' => $discountedNet,
                'tax_total_minor' => $discountedTax,
                'gross_minor' => $discountedGross,
                'tax_snapshot' => $taxRates,
                'memo' => $memo,
                'created_by' => $actorId,
                'request_id' => $requestId,
            ]);
            $quotation->save();

            foreach ($lineRows as $row) {
                $line = new QuotationLine;
                $line->fill($row);
                $line->save();
            }

            $this->auditWriter->write(
                action: 'quotation.created',
                entityType: 'quotation',
                entityId: $quotationId,
                detail: [
                    'quotationNo' => $quotationNo,
                    'customerId' => $customerId,
                    'discountPercent' => $discountPercent,
                    'grossMinor' => $discountedGross,
                    'lineCount' => count($lineRows),
                ],
                actorId: $actorId,
            );

            $this->outboxWriter->record([
                'aggregate_type' => 'quotation',
                'aggregate_id' => $quotationId,
                'event_type' => 'quotation.created',
                'payload' => [
                    'quotationId' => $quotationId,
                    'quotationNo' => $quotationNo,
                    'customerId' => $customerId,
                ],
            ]);

            return $this->toArray(Quotation::query()->with('lines')->findOrFail($quotationId));
        });
    }

    /**
     * @return array{vat: float, nhil: float, getfund: float, covid: float}
     */
    private function resolveTaxRates(string $companyId, string $date): array
    {
        $defaults = ['vat' => 0.15, 'nhil' => 0.025, 'getfund' => 0.025, 'covid' => 0.01];
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
                return (float) $c[$key];
            }

            return $fallback;
        };

        return [
            'vat' => $pick($components, 'vat', $defaults['vat']),
            'nhil' => $pick($components, 'nhil', $defaults['nhil']),
            'getfund' => $pick($components, 'getfund', $defaults['getfund']),
            'covid' => $pick($components, 'covid', $defaults['covid']),
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
            $seq->prefix = 'QT';
            $seq->save();
        }

        $value = $seq->next_value;
        $seq->next_value = $value + 1;
        $seq->save();

        $prefix = $seq->prefix ?: 'QT';

        return sprintf('%s-%s-%05d', $prefix, $year, $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(Quotation $quotation): array
    {
        return [
            'id' => $quotation->id,
            'quotationNo' => $quotation->quotation_no,
            'customerId' => $quotation->customer_id,
            'quotationDate' => $quotation->quotation_date?->format('Y-m-d'),
            'validUntil' => $quotation->valid_until?->format('Y-m-d'),
            'currency' => $quotation->currency,
            'status' => $quotation->status,
            'discountPercent' => (float) $quotation->discount_percent,
            'netMinor' => $quotation->net_minor,
            'taxTotalMinor' => $quotation->tax_total_minor,
            'grossMinor' => $quotation->gross_minor,
            'memo' => $quotation->memo,
            'convertedSalesOrderId' => $quotation->converted_sales_order_id,
            'lines' => $quotation->lines->map(static fn (QuotationLine $l) => [
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
            ])->all(),
            'createdAt' => $quotation->created_at?->toIso8601String(),
        ];
    }
}
