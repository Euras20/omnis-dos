<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Queries;

use App\Models\SavedReport;
use App\Shared\Company\CompanyContext;

/**
 * GET /api/v1/reports/saved — own + shared saved reports.
 */
final class ListSavedReportsQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
    }

    /**
     * @return array{reports: list<array<string, mixed>>}
     */
    public function execute(): array
    {
        $companyId = $this->companyContext->companyId();
        $userId = $this->companyContext->userId();

        $rows = SavedReport::query()
            ->where('company_id', $companyId)
            ->where(function ($q) use ($userId): void {
                $q->where('owner_id', $userId)->orWhere('is_shared', true);
            })
            ->orderByDesc('created_at')
            ->get();

        return [
            'reports' => $rows->map(fn (SavedReport $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'key' => $r->report_key,
                'settings' => $r->settings ?? [],
                'shared' => $r->is_shared,
                'ownerId' => $r->owner_id,
                'createdAt' => $r->created_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
