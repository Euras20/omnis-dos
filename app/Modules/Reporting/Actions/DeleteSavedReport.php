<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Models\SavedReport;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * DELETE /api/v1/reports/saved/{id} — owner only.
 */
final class DeleteSavedReport
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    public function execute(string $id): void
    {
        $companyId = $this->companyContext->companyId();
        $userId = $this->companyContext->userId();

        DB::transaction(function () use ($id, $companyId, $userId): void {
            $report = SavedReport::query()
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->first();

            if ($report === null) {
                throw new NotFoundException('Saved report not found.');
            }

            if ($report->owner_id !== $userId) {
                throw new ForbiddenException('Only the owner can remove this report.');
            }

            $report->delete();

            $this->auditWriter->write(
                'report.deleted',
                'saved_report',
                $id,
                ['name' => $report->name],
                $userId,
            );
        });
    }
}
