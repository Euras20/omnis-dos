<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Queries;

use App\Models\ReportFavourite;
use App\Shared\Company\CompanyContext;

/**
 * GET /api/v1/reports/favourites
 */
final class ListFavouritesQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
    }

    /**
     * @return array{favourites: list<string>}
     */
    public function execute(): array
    {
        $keys = ReportFavourite::query()
            ->where('company_id', $this->companyContext->companyId())
            ->where('user_id', $this->companyContext->userId())
            ->orderBy('report_key')
            ->pluck('report_key')
            ->all();

        return ['favourites' => $keys];
    }
}
