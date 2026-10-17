<?php

declare(strict_types=1);

namespace App\Modules\Sales\Queries;

use App\Models\PricingParameter;
use App\Modules\Sales\Actions\UpsertPricingParameters;
use App\Shared\Company\CompanyContext;

/**
 * GET /api/v1/pricing/parameters — current company pricing parameters (defaults if none saved).
 */
final class GetPricingParametersQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $companyId = $this->companyContext->companyId();

        $row = PricingParameter::query()
            ->where('company_id', $companyId)
            ->first();

        if ($row === null) {
            return [
                'id' => null,
                'companyId' => $companyId,
                'parameters' => PricingParameter::defaults(),
                'rowVersion' => null,
                'updatedAt' => null,
            ];
        }

        return UpsertPricingParameters::toArray($row);
    }
}
