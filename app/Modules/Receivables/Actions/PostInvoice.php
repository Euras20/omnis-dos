<?php

declare(strict_types=1);

namespace App\Modules\Receivables\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\NumberSequence;
use App\Models\Party;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\TaxRuleVersion;
use App\Modules\Ledger\Domain\PostingKey;
use App\Modules\Ledger\Domain\PostingLine;
use App\Modules\Ledger\Domain\PostingRequest;
use App\Modules\Ledger\Services\PostingService;
use App\Modules\Receivables\Domain\TaxBreakdown;
use App\Modules\Receivables\Services\AccountResolver;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Outbox\OutboxWriter;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/invoices — post a customer invoice (one journal).
 */
final class PostInvoice
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly PostingService $postingService,
        private readonly AccountResolver $accountResolver,
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
        $this->permissionChecker->assertCan($actorId, 'ar.invoice.post');

        $companyId = $this->companyContext->companyId();
        $customerId = (string) ($data['customerId'] ?? '');
        $invoiceDate = (string) ($data['invoiceDate'] ?? $data['date'] ?? '');
        $rawLines = $data['lines'] ?? [];

        if ($customerId === '') {
            throw new BusinessRuleException('customerId is required.');
        }
        if ($invoiceDate === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoiceDate)) {
            throw new BusinessRuleException('invoiceDate must be YYYY-MM-DD.');
        }
        if (! is_array($rawLines) || $rawLines === []) {
            throw new BusinessRuleException('At least one invoice line is required.');
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

        $taxRates = $this->resolveTaxRates($companyId, $invoiceDate);
        $currency = strtoupper((string) ($data['currency'] ?? $customer->currency ?? 'GHS'));
        $dueDate = isset($data['dueDate']) ? (string) $data['dueDate'] : null;
        $memo = isset($data['memo']) ? trim((string) $data['memo']) : null;
        $requestId = $this->companyContext->requestId();

        $salesOrderId = isset($data['salesOrderId']) && $data['salesOrderId'] !== ''
            ? (string) $data['salesOrderId']
            : null;

        return DB::transaction(function () use (
            $companyId,
            $customerId,
            $invoiceDate,
            $dueDate,
            $rawLines,
            $taxRates,
            $currency,
            $memo,
            $actorId,
            $requestId,
            $salesOrderId,
        ) {
            // When billing from an order, lock the order and require confirmed status.
            // Delivery is NOT required to invoice (advance / pre-delivery billing is allowed);
            // double-billing is prevented via sales_order_line.qty_invoiced.
            if ($salesOrderId !== null) {
                $order = SalesOrder::query()
                    ->where('company_id', $companyId)
                    ->where('id', $salesOrderId)
                    ->lockForUpdate()
                    ->first();
                if ($order === null) {
                    throw new NotFoundException('Sales order not found.');
                }
                if ((string) $order->customer_id !== $customerId) {
                    throw new BusinessRuleException('salesOrderId does not belong to this customer.');
                }
                if (! in_array($order->status, ['confirmed', 'closed'], true)) {
                    throw new BusinessRuleException(
                        'Only confirmed sales orders can be invoiced (current status: '.$order->status.').'
                    );
                }
            }

            // Order lines used to auto-link invoice lines when salesOrderLineId is omitted.
            $orderLines = $salesOrderId === null
                ? collect()
                : SalesOrderLine::query()
                    ->where('company_id', $companyId)
                    ->where('sales_order_id', $salesOrderId)
                    ->orderBy('line_no')
                    ->lockForUpdate()
                    ->get();
            $claimedOrderLineIds = [];

            $invoiceId = Uuid::v7();
            $invoiceNo = $this->nextNumber($companyId, $invoiceDate, 'invoice');

            $headerNet = 0;
            $headerVat = 0;
            $headerNhil = 0;
            $headerGetfund = 0;
            $headerCovid = 0;
            $lineModels = [];

            foreach ($rawLines as $i => $raw) {
                if (! is_array($raw)) {
                    throw new BusinessRuleException("Line {$i} is invalid.");
                }
                $description = trim((string) ($raw['description'] ?? ''));
                if ($description === '') {
                    throw new BusinessRuleException("Line {$i} requires description.");
                }
                $qty = (float) ($raw['qty'] ?? $raw['quantity'] ?? 0);
                if ($qty <= 0) {
                    throw new BusinessRuleException("Line {$i} qty must be positive.");
                }
                $unitPrice = (int) ($raw['unitPriceMinor'] ?? $raw['unitPrice'] ?? 0);
                if ($unitPrice < 0) {
                    throw new BusinessRuleException("Line {$i} unitPriceMinor cannot be negative.");
                }

                $salesOrderLineId = isset($raw['salesOrderLineId']) && $raw['salesOrderLineId'] !== ''
                    ? (string) $raw['salesOrderLineId']
                    : null;

                // When the client sends the order but not the line id, link to the
                // existing sales_order_line so qty_invoiced is still updated.
                if ($salesOrderId !== null && $salesOrderLineId === null) {
                    $salesOrderLineId = $this->resolveSalesOrderLineId(
                        $orderLines,
                        $raw,
                        $i,
                        count($rawLines),
                        $claimedOrderLineIds,
                    );
                }
                if ($salesOrderLineId !== null) {
                    $claimedOrderLineIds[$salesOrderLineId] = true;
                }

                if ($salesOrderLineId !== null) {
                    $soLine = SalesOrderLine::query()
                        ->where('company_id', $companyId)
                        ->where('id', $salesOrderLineId)
                        ->lockForUpdate()
                        ->first();
                    if ($soLine === null) {
                        throw new NotFoundException("Line {$i}: sales order line not found.");
                    }
                    if ($salesOrderId !== null && (string) $soLine->sales_order_id !== $salesOrderId) {
                        throw new BusinessRuleException(
                            "Line {$i}: salesOrderLineId does not belong to salesOrderId."
                        );
                    }
                    // Infer header sales_order_id from line when omitted.
                    if ($salesOrderId === null) {
                        $salesOrderId = (string) $soLine->sales_order_id;
                        $order = SalesOrder::query()
                            ->where('company_id', $companyId)
                            ->where('id', $salesOrderId)
                            ->lockForUpdate()
                            ->first();
                        if ($order === null) {
                            throw new NotFoundException('Sales order not found.');
                        }
                        if ((string) $order->customer_id !== $customerId) {
                            throw new BusinessRuleException('Order customer does not match invoice customer.');
                        }
                        if (! in_array($order->status, ['confirmed', 'closed'], true)) {
                            throw new BusinessRuleException(
                                'Only confirmed sales orders can be invoiced (current status: '.$order->status.').'
                            );
                        }
                    }

                    $ordered = (float) $soLine->qty;
                    $alreadyInvoiced = (float) $soLine->qty_invoiced;
                    $remaining = $ordered - $alreadyInvoiced;
                    if ($qty > $remaining + 0.0005) {
                        throw new BusinessRuleException(
                            "Line {$i}: cannot invoice {$qty}; only {$remaining} remaining on order line "
                            ."(ordered {$ordered}, already invoiced {$alreadyInvoiced})."
                        );
                    }

                    $soLine->qty_invoiced = number_format($alreadyInvoiced + $qty, 3, '.', '');
                    $soLine->save();
                }

                $net = (int) round($qty * $unitPrice);
                $tax = TaxBreakdown::fromNet($net, $taxRates);

                $lineId = Uuid::v7();
                $line = new InvoiceLine;
                $line->id = $lineId;
                $line->company_id = $companyId;
                $line->invoice_id = $invoiceId;
                $line->line_no = $i + 1;
                $line->item_id = isset($raw['itemId']) ? (string) $raw['itemId'] : null;
                $line->sales_order_line_id = $salesOrderLineId;
                $line->description = $description;
                $line->qty = $qty;
                $line->unit = (string) ($raw['unit'] ?? 'pcs');
                $line->unit_price_minor = $unitPrice;
                $line->net_minor = $tax->netMinor;
                $line->vat_minor = $tax->vatMinor;
                $line->nhil_minor = $tax->nhilMinor;
                $line->getfund_minor = $tax->getfundMinor;
                $line->covid_minor = $tax->covidMinor;
                $line->gross_minor = $tax->grossMinor();
                $lineModels[] = $line;

                $headerNet += $tax->netMinor;
                $headerVat += $tax->vatMinor;
                $headerNhil += $tax->nhilMinor;
                $headerGetfund += $tax->getfundMinor;
                $headerCovid += $tax->covidMinor;
            }

            $taxTotal = $headerVat + $headerNhil + $headerGetfund + $headerCovid;
            $gross = $headerNet + $taxTotal;

            $invoice = new Invoice;
            $invoice->id = $invoiceId;
            $invoice->company_id = $companyId;
            $invoice->invoice_no = $invoiceNo;
            $invoice->customer_id = $customerId;
            $invoice->sales_order_id = $salesOrderId;
            $invoice->invoice_date = $invoiceDate;
            $invoice->due_date = $dueDate;
            $invoice->currency = $currency;
            $invoice->net_minor = $headerNet;
            $invoice->vat_minor = $headerVat;
            $invoice->nhil_minor = $headerNhil;
            $invoice->getfund_minor = $headerGetfund;
            $invoice->covid_minor = $headerCovid;
            $invoice->tax_total_minor = $taxTotal;
            $invoice->gross_minor = $gross;
            $invoice->tax_snapshot = $taxRates;
            $invoice->status = 'posted';
            $invoice->memo = $memo;
            $invoice->created_by = $actorId;
            $invoice->request_id = $requestId;
            $invoice->save();

            foreach ($lineModels as $line) {
                $line->save();
            }

            // Posting: Dr AR (gross), Cr revenue (net), Cr tax accounts
            $arId = $this->accountResolver->idByCode($companyId, 'ar');
            $revenueId = $this->accountResolver->idByCode($companyId, 'revenue');
            $lines = [
                new PostingLine(accountId: $arId, debitMinor: $gross, creditMinor: 0, partyId: $customerId, memo: "Invoice {$invoiceNo}"),
                new PostingLine(accountId: $revenueId, debitMinor: 0, creditMinor: $headerNet, partyId: $customerId, memo: "Invoice {$invoiceNo}"),
            ];
            if ($headerVat > 0) {
                $lines[] = new PostingLine(
                    accountId: $this->accountResolver->idByCode($companyId, 'vat'),
                    debitMinor: 0,
                    creditMinor: $headerVat,
                    memo: "VAT {$invoiceNo}",
                );
            }
            if ($headerNhil > 0) {
                $lines[] = new PostingLine(
                    accountId: $this->accountResolver->idByCode($companyId, 'nhil'),
                    debitMinor: 0,
                    creditMinor: $headerNhil,
                    memo: "NHIL {$invoiceNo}",
                );
            }
            if ($headerGetfund > 0) {
                $lines[] = new PostingLine(
                    accountId: $this->accountResolver->idByCode($companyId, 'getfund'),
                    debitMinor: 0,
                    creditMinor: $headerGetfund,
                    memo: "GETFund {$invoiceNo}",
                );
            }
            if ($headerCovid > 0) {
                $lines[] = new PostingLine(
                    accountId: $this->accountResolver->idByCode($companyId, 'covid'),
                    debitMinor: 0,
                    creditMinor: $headerCovid,
                    memo: "COVID {$invoiceNo}",
                );
            }

            $journalId = $this->postingService->post(new PostingRequest(
                companyId: $companyId,
                postingDate: $invoiceDate,
                postingKey: PostingKey::make('invoice', $invoiceId, 'post'),
                sourceType: 'invoice',
                sourceId: $invoiceId,
                eventType: 'post',
                description: "Invoice {$invoiceNo}",
                lines: $lines,
                actorId: $actorId,
                requestId: $requestId,
                currency: $currency,
            ));

            $invoice->journal_id = $journalId;
            $invoice->save();

            $this->auditWriter->write(
                action: 'invoice.posted',
                entityType: 'invoice',
                entityId: $invoiceId,
                detail: [
                    'invoiceNo' => $invoiceNo,
                    'customerId' => $customerId,
                    'grossMinor' => $gross,
                    'journalId' => $journalId,
                ],
                actorId: $actorId,
            );

            $this->outboxWriter->record([
                'aggregate_type' => 'invoice',
                'aggregate_id' => $invoiceId,
                'event_type' => 'invoice.posted',
                'payload' => [
                    'invoiceId' => $invoiceId,
                    'invoiceNo' => $invoiceNo,
                    'customerId' => $customerId,
                    'grossMinor' => $gross,
                ],
            ]);

            return $this->toArray(Invoice::query()->with('lines')->findOrFail($invoiceId));
        });
    }

    /**
     * Pick the existing sales_order_line id when the client omitted it.
     *
     * Match order: itemId, lineNo, only remaining line, same-count positional.
     *
     * @param  \Illuminate\Support\Collection<int, SalesOrderLine>  $orderLines
     * @param  array<string, mixed>  $raw
     * @param  array<string, true>  $claimed
     */
    private function resolveSalesOrderLineId(
        $orderLines,
        array $raw,
        int $index,
        int $invoiceLineCount,
        array $claimed,
    ): string {
        $available = $orderLines->filter(
            static fn (SalesOrderLine $l) => ! isset($claimed[(string) $l->id])
                && ((float) $l->qty - (float) $l->qty_invoiced) > 0.0005
        )->values();

        if ($available->isEmpty()) {
            throw new BusinessRuleException(
                "Line {$index}: no uninvoiced sales order line remains to link."
            );
        }

        $itemId = isset($raw['itemId']) && $raw['itemId'] !== '' ? (string) $raw['itemId'] : null;
        if ($itemId !== null) {
            $byItem = $available->filter(
                static fn (SalesOrderLine $l) => (string) $l->item_id === $itemId
            )->values();
            if ($byItem->count() === 1) {
                return (string) $byItem->first()->id;
            }
        }

        $lineNo = isset($raw['lineNo']) ? (int) $raw['lineNo'] : 0;
        if ($lineNo > 0) {
            $byNo = $available->first(
                static fn (SalesOrderLine $l) => (int) $l->line_no === $lineNo
            );
            if ($byNo !== null) {
                return (string) $byNo->id;
            }
        }

        $description = strtolower(trim((string) ($raw['description'] ?? '')));
        if ($description !== '') {
            $byDesc = $available->filter(
                static fn (SalesOrderLine $l) => strtolower(trim((string) $l->description)) === $description
            )->values();
            if ($byDesc->count() === 1) {
                return (string) $byDesc->first()->id;
            }
        }

        if ($available->count() === 1) {
            return (string) $available->first()->id;
        }

        if ($invoiceLineCount === $orderLines->count() && $orderLines->has($index)) {
            $positional = $orderLines->values()->get($index);
            if ($positional !== null && ! isset($claimed[(string) $positional->id])) {
                return (string) $positional->id;
            }
        }

        $choices = $available->map(
            static fn (SalesOrderLine $l) => $l->line_no.':'.$l->id
        )->implode(', ');

        throw new BusinessRuleException(
            "Line {$index}: could not pick a sales order line automatically. "
            ."Send salesOrderLineId. Available: {$choices}."
        );
    }

    /**
     * @return array{vat: float, nhil: float, getfund: float}
     */
    private function resolveTaxRates(string $companyId, string $invoiceDate): array
    {
        $rule = TaxRuleVersion::query()
            ->where('company_id', $companyId)
            ->where('effective_from', '<=', $invoiceDate)
            ->where('is_active', true)
            ->orderByDesc('effective_from')
            ->first();

        $defaults = ['vat' => 0.15, 'nhil' => 0.025, 'getfund' => 0.025];
        if ($rule === null) {
            return $defaults;
        }

        $components = is_array($rule->components) ? $rule->components : [];
        $pick = static function (array $c, string $key, float $default): float {
            if (! array_key_exists($key, $c)) {
                return $default;
            }
            $v = (float) $c[$key];

            return $v > 1 ? $v / 100.0 : $v;
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
        $year = (int) substr($date, 0, 4);
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
            $seq->prefix = strtoupper(substr($docType, 0, 3));
            $seq->pad_length = 5;
            $seq->save();
            // Re-select with lock so concurrent creators cannot race past us.
            $seq = NumberSequence::query()
                ->where('id', $seq->id)
                ->lockForUpdate()
                ->first();
        }

        $value = (int) $seq->next_value;
        $seq->next_value = $value + 1;
        $seq->save();

        $prefix = $seq->prefix ?: strtoupper(substr($docType, 0, 3));
        $pad = max(1, (int) ($seq->pad_length ?? 5));

        return sprintf('%s-%d-%s', $prefix, $year, str_pad((string) $value, $pad, '0', STR_PAD_LEFT));
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'invoiceNo' => $invoice->invoice_no,
            'customerId' => $invoice->customer_id,
            'salesOrderId' => $invoice->sales_order_id,
            'invoiceDate' => $invoice->invoice_date?->format('Y-m-d'),
            'dueDate' => $invoice->due_date?->format('Y-m-d'),
            'currency' => $invoice->currency,
            'netMinor' => $invoice->net_minor,
            'tax' => [
                'vat' => $invoice->vat_minor,
                'nhil' => $invoice->nhil_minor,
                'getfund' => $invoice->getfund_minor,
                'covid' => $invoice->covid_minor,
                'total' => $invoice->tax_total_minor,
            ],
            'grossMinor' => $invoice->gross_minor,
            'status' => $invoice->status,
            'journalId' => $invoice->journal_id,
            'memo' => $invoice->memo,
            'lines' => $invoice->lines->map(static fn (InvoiceLine $l) => [
                'id' => $l->id,
                'lineNo' => $l->line_no,
                'itemId' => $l->item_id,
                'salesOrderLineId' => $l->sales_order_line_id,
                'description' => $l->description,
                'qty' => (float) $l->qty,
                'unit' => $l->unit,
                'unitPriceMinor' => $l->unit_price_minor,
                'netMinor' => $l->net_minor,
                'grossMinor' => $l->gross_minor,
            ])->values()->all(),
            'createdAt' => $invoice->created_at?->toIso8601String(),
        ];
    }
}
