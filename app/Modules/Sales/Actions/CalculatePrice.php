<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Item;
use App\Models\PricingParameter;
use App\Modules\Sales\Services\PricingCalculator;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * POST /api/v1/pricing/calculate — pure calculation matching frontend priceBuild.
 *
 * Returns the full breakdown (GHS per unit). Does not persist.
 * Quotations and sales orders store the resulting unit price on their lines.
 */
final class CalculatePrice
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly PricingCalculator $calculator,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function execute(array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'sales.quotes.view');

        $companyId = $this->companyContext->companyId();

        $itemId = (string) ($data['itemId'] ?? $data['sku'] ?? '');
        $qty = (int) ($data['qty'] ?? 1);
        $estimate = (bool) ($data['estimate'] ?? false);
        $asOfDate = isset($data['asOfDate']) ? (string) $data['asOfDate'] : null;

        if ($itemId === '') {
            throw new BusinessRuleException('itemId or sku is required.');
        }
        if ($qty < 1) {
            throw new BusinessRuleException('qty must be at least 1.');
        }

        $item = Item::query()
            ->where('company_id', $companyId)
            ->where(function ($q) use ($itemId) {
                $q->where('id', $itemId)->orWhere('sku', $itemId);
            })
            ->where('is_active', true)
            ->first();

        if ($item === null) {
            throw new NotFoundException('Item not found.');
        }

        $parameters = $this->loadParameters($companyId);

        $buyPriceMinor = $item->buy_price_minor;
        if (isset($data['buyPriceMinor']) && is_numeric($data['buyPriceMinor'])) {
            $buyPriceMinor = (int) $data['buyPriceMinor'];
        }

        $dimensions = is_array($item->dimensions) ? $item->dimensions : null;

        $result = $this->calculator->calculate(
            $parameters,
            [
                'weightKg' => $item->weight_kg !== null ? (float) $item->weight_kg : null,
                'dimensions' => $dimensions,
                'buyPriceMinor' => $buyPriceMinor,
            ],
            $qty,
            $estimate,
            $asOfDate,
            $companyId,
        );

        $result['itemId'] = $item->id;
        $result['sku'] = $item->sku;
        $result['unitPriceMinor'] = (int) round($result['price'] * 100);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadParameters(string $companyId): array
    {
        $row = PricingParameter::query()
            ->where('company_id', $companyId)
            ->first();

        if ($row === null || ! is_array($row->parameters)) {
            return PricingParameter::defaults();
        }

        return array_merge(PricingParameter::defaults(), $row->parameters);
    }
}
