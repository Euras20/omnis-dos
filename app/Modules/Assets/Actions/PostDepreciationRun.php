<?php

declare(strict_types=1);

namespace App\Modules\Assets\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\Account;
use App\Models\AssetClass;
use App\Models\DepreciationLine;
use App\Models\DepreciationRun;
use App\Models\FixedAsset;
use App\Modules\Ledger\Domain\PostingKey;
use App\Modules\Ledger\Domain\PostingLine;
use App\Modules\Ledger\Domain\PostingRequest;
use App\Modules\Ledger\Services\PostingService;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\PeriodLock\PeriodLockChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/assets/depreciation/{month} — post monthly depreciation once.
 */
final class PostDepreciationRun
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly PostingService $postingService,
        private readonly PeriodLockChecker $periodLockChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $periodMonth): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'assets.manage');

        if (! preg_match('/^\d{4}-\d{2}$/', $periodMonth)) {
            throw new BusinessRuleException('periodMonth must be YYYY-MM.');
        }

        $companyId = $this->companyContext->companyId();
        $postingDate = $periodMonth.'-28';
        $this->periodLockChecker->assertOpen($postingDate);

        return DB::transaction(function () use ($companyId, $actorId, $periodMonth, $postingDate) {
            $exists = DepreciationRun::query()
                ->where('company_id', $companyId)
                ->where('period_month', $periodMonth)
                ->exists();
            if ($exists) {
                throw new BusinessRuleException('Depreciation already posted for this period.');
            }

            $assets = FixedAsset::query()
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->where('acquisition_date', '<=', $periodMonth.'-28')
                ->get();

            $classes = AssetClass::query()
                ->where('company_id', $companyId)
                ->get()
                ->keyBy('id');

            $runId = Uuid::v7();
            $totalCharge = 0;
            $glByAccount = []; // accountId => charge

            $run = new DepreciationRun([
                'id' => $runId,
                'company_id' => $companyId,
                'period_month' => $periodMonth,
                'status' => 'posted',
                'posted_by' => $actorId,
                'posted_at' => now(),
                'request_id' => $this->companyContext->requestId(),
            ]);
            $run->save();

            foreach ($assets as $asset) {
                $class = $classes->get($asset->asset_class_id);
                $accum = (int) DB::table('depreciation_line')
                    ->where('fixed_asset_id', $asset->id)
                    ->sum('charge_minor');

                $depreciable = (int) $asset->cost_minor - (int) $asset->residual_minor;
                $remaining = max(0, $depreciable - $accum);
                if ($remaining <= 0) {
                    continue;
                }

                $life = max(1, (int) $asset->useful_life_months);
                $monthly = (int) floor($depreciable / $life);
                $charge = min($monthly, $remaining);
                if ($charge <= 0) {
                    continue;
                }

                $accumAfter = $accum + $charge;
                $nbv = (int) $asset->cost_minor - $accumAfter;

                $line = new DepreciationLine([
                    'company_id' => $companyId,
                    'depreciation_run_id' => $runId,
                    'fixed_asset_id' => $asset->id,
                    'charge_minor' => $charge,
                    'accum_before_minor' => $accum,
                    'accum_after_minor' => $accumAfter,
                    'nbv_after_minor' => $nbv,
                ]);
                $line->save();

                $totalCharge += $charge;

                $expenseAcc = $class?->depr_expense_account_id;
                $accumAcc = $class?->accum_depr_account_id;
                if ($expenseAcc && $accumAcc) {
                    $glByAccount[$expenseAcc] = ($glByAccount[$expenseAcc] ?? 0) + $charge;
                    $glByAccount[$accumAcc] = ($glByAccount[$accumAcc] ?? 0) - $charge; // credit as negative for later split
                }
            }

            if ($totalCharge === 0) {
                // still record empty run for uniqueness
                $run->total_charge_minor = 0;
                $run->save();

                return [
                    'id' => $runId,
                    'periodMonth' => $periodMonth,
                    'totalChargeMinor' => 0,
                    'lineCount' => 0,
                    'journalId' => null,
                ];
            }

            // Resolve default accounts if class accounts missing
            $defaultExpense = Account::query()->where('company_id', $companyId)->where('code', '5600')->first();
            $defaultAccum = Account::query()->where('company_id', $companyId)->where('code', '1510')->first();

            $postingLines = [];
            $expenseTotal = 0;
            $accumTotal = 0;

            foreach ($glByAccount as $accId => $signed) {
                if ($signed > 0) {
                    $postingLines[] = new PostingLine(
                        accountId: $accId,
                        debitMinor: $signed,
                        creditMinor: 0,
                        memo: 'Depreciation '.$periodMonth,
                    );
                    $expenseTotal += $signed;
                } else {
                    $postingLines[] = new PostingLine(
                        accountId: $accId,
                        debitMinor: 0,
                        creditMinor: abs($signed),
                        memo: 'Accumulated depreciation '.$periodMonth,
                    );
                    $accumTotal += abs($signed);
                }
            }

            if ($postingLines === [] && $defaultExpense && $defaultAccum) {
                $postingLines[] = new PostingLine(
                    accountId: $defaultExpense->id,
                    debitMinor: $totalCharge,
                    creditMinor: 0,
                    memo: 'Depreciation '.$periodMonth,
                );
                $postingLines[] = new PostingLine(
                    accountId: $defaultAccum->id,
                    debitMinor: 0,
                    creditMinor: $totalCharge,
                    memo: 'Accumulated depreciation '.$periodMonth,
                );
            }

            if ($postingLines === []) {
                throw new BusinessRuleException('No depreciation expense / accum accounts configured.');
            }

            $journalId = $this->postingService->post(new PostingRequest(
                companyId: $companyId,
                postingDate: $postingDate,
                postingKey: PostingKey::make('depreciation', $runId, 'post'),
                sourceType: 'depreciation',
                sourceId: $runId,
                eventType: 'post',
                description: 'Depreciation run '.$periodMonth,
                lines: $postingLines,
                actorId: $actorId,
                requestId: $this->companyContext->requestId(),
                currency: 'GHS',
            ));

            $run->total_charge_minor = $totalCharge;
            $run->journal_id = $journalId;
            $run->save();

            $this->auditWriter->write(
                action: 'depreciation_run.posted',
                entityType: 'depreciation_run',
                entityId: $runId,
                detail: ['period_month' => $periodMonth, 'total_charge_minor' => $totalCharge],
            );

            return [
                'id' => $runId,
                'periodMonth' => $periodMonth,
                'totalChargeMinor' => $totalCharge,
                'journalId' => $journalId,
                'status' => 'posted',
            ];
        });
    }
}
