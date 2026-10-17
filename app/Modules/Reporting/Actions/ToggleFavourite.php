<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Models\ReportFavourite;
use App\Shared\Company\CompanyContext;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/reports/favourites — toggle a favourite for the current user.
 */
final class ToggleFavourite
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
    }

    /**
     * @param  array{reportKey: string}  $data
     * @return array{favourited: bool, reportKey: string}
     */
    public function execute(array $data): array
    {
        $companyId = $this->companyContext->companyId();
        $userId = $this->companyContext->userId();
        $key = (string) $data['reportKey'];

        return DB::transaction(function () use ($companyId, $userId, $key): array {
            $existing = ReportFavourite::query()
                ->where('company_id', $companyId)
                ->where('user_id', $userId)
                ->where('report_key', $key)
                ->first();

            if ($existing !== null) {
                $existing->delete();

                return ['favourited' => false, 'reportKey' => $key];
            }

            ReportFavourite::query()->create([
                'id' => Uuid::v7(),
                'company_id' => $companyId,
                'user_id' => $userId,
                'report_key' => $key,
            ]);

            return ['favourited' => true, 'reportKey' => $key];
        });
    }
}
