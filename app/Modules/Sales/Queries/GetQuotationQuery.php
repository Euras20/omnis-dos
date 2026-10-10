<?php

declare(strict_types=1);

namespace App\Modules\Sales\Queries;

use App\Exceptions\NotFoundException;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * GET /api/v1/quotations/{id}
 */
final class GetQuotationQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $id): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'sales.quotes.view');

        $companyId = $this->companyContext->companyId();
        $quotation = Quotation::query()
            ->with('lines')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if ($quotation === null) {
            throw new NotFoundException('Quotation not found.');
        }

        return [
            'id' => $quotation->id,
            'quotationNo' => $quotation->quotation_no,
            'customerId' => $quotation->customer_id,
            'quotationDate' => $quotation->quotation_date?->format('Y-m-d'),
            'validUntil' => $quotation->valid_until?->format('Y-m-d'),
            'currency' => $quotation->currency,
            'status' => $quotation->status,
            'netMinor' => $quotation->net_minor,
            'taxTotalMinor' => $quotation->tax_total_minor,
            'grossMinor' => $quotation->gross_minor,
            'taxSnapshot' => $quotation->tax_snapshot,
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
