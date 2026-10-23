<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Item;
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
 * PUT /api/v1/quotations/{id} — edit a quotation (lines and/or discount).
 *
 * Discount is a simple percentage applied to the pre-tax total.
 * Example: lines sum to 1000 minor units, discountPercent 30 → net 700.
 * No approval workflow. Allowed while draft or sent (so the quotation can be resent).
 */
final class UpdateQuotation
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
    public function execute(string $id, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'sales.quotes.manage');

        $companyId = $this->companyContext->companyId();
        $requestId = $this->companyContext->requestId();

        return DB::transaction(function () use ($companyId, $id, $data, $actorId, $requestId) {
            $quotation = Quotation::query()
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($quotation === null) {
                throw new NotFoundException('Quotation not found.');
            }

            if ($quotation->status === QuotationStatus::CONVERTED) {
                throw new BusinessRuleException('Converted quotations cannot be edited.');
            }
            if (! in_array($quotation->status, [QuotationStatus::DRAFT, QuotationStatus::SENT], true)) {
                throw new BusinessRuleException(
                    "Only draft or sent quotations can be edited (current status: {$quotation->status}).",
                );
            }

            $discountPercent = isset($data['discountPercent'])
                ? (float) $data['discountPercent']
                : (float) $quotation->discount_percent;

            if ($discountPercent < 0 || $discountPercent > 100) {
                throw new BusinessRuleException('discountPercent must be between 0 and 100.');
            }

            $quotationDate = $quotation->quotation_date?->format('Y-m-d') ?? date('Y-m-d');
            $taxRates = $this->resolveTaxRates($companyId, $quotationDate);
            $combinedTaxRate = $taxRates['vat'] + $taxRates['nhil'] + $taxRates['getfund'];

            $rawLines = $data['lines'] ?? null;
            $lineRows = [];

            if (is_array($rawLines)) {
                if ($rawLines === []) {
                    throw new BusinessRuleException('At least one quotation line is required.');
                }

                $headerPreDiscountNet = 0;

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

                    $lineNet = (int) round((float) $qty * $unitPrice);
                    $headerPreDiscountNet += $lineNet;

                    $lineRows[] = [
                        'id' => Uuid::v7(),
                        'company_id' => $companyId,
                        'quotation_id' => $quotation->id,
                        'line_no' => $index + 1,
                        'item_id' => $itemId ?: null,
                        'description' => $description,
                        'qty' => $qty,
                        'unit' => $unit,
                        'unit_price_minor' => $unitPrice,
                        'net_minor' => $lineNet,
                        'tax_minor' => 0,
                        'gross_minor' => $lineNet,
                    ];
                }
            } else {
                $headerPreDiscountNet = (int) QuotationLine::query()
                    ->where('quotation_id', $quotation->id)
                    ->sum('net_minor');
            }

            $discountedNet = (int) round($headerPreDiscountNet * (1 - $discountPercent / 100));
            $tax = (int) round($discountedNet * $combinedTaxRate);
            $gross = $discountedNet + $tax;

            if (isset($data['validUntil'])) {
                $quotation->valid_until = $data['validUntil'] !== null ? (string) $data['validUntil'] : null;
            }
            if (isset($data['currency'])) {
                $quotation->currency = strtoupper((string) $data['currency']);
            }
            if (array_key_exists('memo', $data)) {
                $quotation->memo = $data['memo'] !== null ? trim((string) $data['memo']) : null;
            }

            $quotation->discount_percent = $discountPercent;
            $quotation->net_minor = $discountedNet;
            $quotation->tax_total_minor = $tax;
            $quotation->gross_minor = $gross;
            $quotation->tax_snapshot = $taxRates;
            $quotation->request_id = $requestId;
            $quotation->save();

            if ($lineRows !== []) {
                QuotationLine::query()->where('quotation_id', $quotation->id)->delete();
                foreach ($lineRows as $row) {
                    $line = new QuotationLine;
                    $line->fill($row);
                    $line->save();
                }
            }

            $this->auditWriter->write(
                action: 'quotation.updated',
                entityType: 'quotation',
                entityId: $quotation->id,
                detail: [
                    'quotationNo' => $quotation->quotation_no,
                    'discountPercent' => $discountPercent,
                    'preDiscountNetMinor' => $headerPreDiscountNet,
                    'netMinor' => $discountedNet,
                    'grossMinor' => $gross,
                ],
                actorId: $actorId,
            );

            $this->outboxWriter->record([
                'aggregate_type' => 'quotation',
                'aggregate_id' => $quotation->id,
                'event_type' => 'quotation.updated',
                'payload' => [
                    'quotationId' => $quotation->id,
                    'quotationNo' => $quotation->quotation_no,
                    'discountPercent' => $discountPercent,
                ],
            ]);

            return $this->toArray(
                Quotation::query()->with('lines')->findOrFail($quotation->id),
                $headerPreDiscountNet,
            );
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

    /**
     * @return array<string, mixed>
     */
    private function toArray(Quotation $quotation, int $preDiscountNet): array
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
            'preDiscountNetMinor' => $preDiscountNet,
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
            'updatedAt' => $quotation->updated_at?->toIso8601String(),
        ];
    }
}
