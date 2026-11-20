<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Item;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * PATCH /api/v1/sales-orders/{id}
 *
 * Changing salesChannel reprices every item line from the item's
 * wholesale or retail price and recalculates totals.
 */
final class UpdateSalesOrder
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function execute(string $id, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'sales.manage');

        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($id, $data, $companyId, $actorId) {
            $order = SalesOrder::query()
                ->with('lines')
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($order === null) {
                throw new NotFoundException('Sales order not found.');
            }
            if (! in_array($order->status, ['draft', 'confirmed'], true)) {
                throw new BusinessRuleException('Only draft or confirmed orders can be updated.');
            }

            if (array_key_exists('salesChannel', $data)) {
                $channel = $data['salesChannel'] !== null ? strtolower((string) $data['salesChannel']) : null;
                if ($channel !== null && ! in_array($channel, ['wholesale', 'retail'], true)) {
                    throw new BusinessRuleException('salesChannel must be wholesale or retail.');
                }
                $order->sales_channel = $channel;
            }

            if (array_key_exists('orderTypeId', $data)) {
                $order->order_type_id = $data['orderTypeId'] !== null && $data['orderTypeId'] !== ''
                    ? (string) $data['orderTypeId']
                    : null;
            }

            $tax = is_array($order->tax_snapshot) ? $order->tax_snapshot : [];
            $rate = (float) ($tax['vat'] ?? 0.15) + (float) ($tax['nhil'] ?? 0.025) + (float) ($tax['getfund'] ?? 0.025);

            $headerNet = 0;
            $headerTax = 0;

            foreach ($order->lines as $line) {
                if ($line->item_id !== null && $order->sales_channel !== null) {
                    $item = Item::query()->find($line->item_id);
                    if ($item !== null) {
                        $price = match ($order->sales_channel) {
                            'wholesale' => (int) ($item->wholesale_price_minor ?? $item->list_price_minor),
                            'retail' => (int) ($item->retail_price_minor ?? $item->list_price_minor),
                            default => (int) $line->unit_price_minor,
                        };
                        $line->unit_price_minor = $price;
                    }
                }

                $net = (int) round((float) $line->qty * $line->unit_price_minor);
                $taxMinor = (int) round($net * $rate);
                $line->net_minor = $net;
                $line->tax_minor = $taxMinor;
                $line->gross_minor = $net + $taxMinor;
                $line->save();

                $headerNet += $net;
                $headerTax += $taxMinor;
            }

            $order->net_minor = $headerNet;
            $order->tax_total_minor = $headerTax;
            $order->gross_minor = $headerNet + $headerTax;
            $order->save();

            $this->auditWriter->write(
                action: 'sales_order.updated',
                entityType: 'sales_order',
                entityId: $order->id,
                detail: [
                    'salesChannel' => $order->sales_channel,
                    'orderTypeId' => $order->order_type_id,
                    'grossMinor' => $order->gross_minor,
                ],
                actorId: $actorId,
            );

            return [
                'id' => $order->id,
                'orderNo' => $order->order_no,
                'salesChannel' => $order->sales_channel,
                'orderTypeId' => $order->order_type_id,
                'netMinor' => $order->net_minor,
                'taxTotalMinor' => $order->tax_total_minor,
                'grossMinor' => $order->gross_minor,
                'lines' => $order->lines->map(static fn (SalesOrderLine $l) => [
                    'id' => $l->id,
                    'itemId' => $l->item_id,
                    'unitPriceMinor' => $l->unit_price_minor,
                    'netMinor' => $l->net_minor,
                    'taxMinor' => $l->tax_minor,
                    'grossMinor' => $l->gross_minor,
                ])->all(),
            ];
        });
    }
}
