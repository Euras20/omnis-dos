<?php

declare(strict_types=1);

namespace App\Modules\Assets\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\AssetClass;
use App\Models\Bill;
use App\Models\FixedAsset;
use App\Models\NumberSequence;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/assets — capitalise from a posted bill (no second GL post).
 */
final class CapitaliseAsset
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
    public function execute(array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'assets.manage');

        $companyId = $this->companyContext->companyId();
        $name = trim((string) ($data['name'] ?? ''));
        $assetClassId = (string) ($data['assetClassId'] ?? $data['asset_class_id'] ?? '');
        $billId = (string) ($data['sourceBillId'] ?? $data['source_bill_id'] ?? '');
        $acquisitionDate = (string) ($data['acquisitionDate'] ?? $data['acquisition_date'] ?? '');

        if ($name === '' || $assetClassId === '') {
            throw new BusinessRuleException('name and assetClassId are required.');
        }
        if ($acquisitionDate === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $acquisitionDate)) {
            throw new BusinessRuleException('acquisitionDate must be YYYY-MM-DD.');
        }

        return DB::transaction(function () use (
            $companyId,
            $actorId,
            $name,
            $assetClassId,
            $billId,
            $acquisitionDate,
            $data
        ) {
            $class = AssetClass::query()
                ->where('company_id', $companyId)
                ->where('id', $assetClassId)
                ->where('is_active', true)
                ->first();
            if ($class === null) {
                throw new NotFoundException('Asset class not found.');
            }

            $costMinor = (int) ($data['costMinor'] ?? $data['cost_minor'] ?? 0);
            $sourceBillId = null;

            if ($billId !== '') {
                $bill = Bill::query()
                    ->where('company_id', $companyId)
                    ->where('id', $billId)
                    ->first();
                if ($bill === null) {
                    throw new NotFoundException('Source bill not found.');
                }
                $already = FixedAsset::query()
                    ->where('company_id', $companyId)
                    ->where('source_bill_id', $billId)
                    ->exists();
                if ($already) {
                    throw new BusinessRuleException('An asset already exists for this bill.');
                }
                $sourceBillId = $billId;
                if ($costMinor <= 0) {
                    $costMinor = (int) $bill->gross_minor;
                }
            }

            if ($costMinor <= 0) {
                throw new BusinessRuleException('costMinor must be > 0.');
            }

            $assetNo = $this->allocateAssetNo($companyId);
            $id = Uuid::v7();
            $life = (int) ($data['usefulLifeMonths'] ?? $data['useful_life_months'] ?? $class->useful_life_months);
            $residual = (int) ($data['residualMinor'] ?? $data['residual_minor'] ?? 0);
            $method = (string) ($data['method'] ?? $class->method ?? 'straight_line');

            $asset = new FixedAsset([
                'id' => $id,
                'company_id' => $companyId,
                'asset_no' => $assetNo,
                'name' => $name,
                'asset_class_id' => $assetClassId,
                'acquisition_date' => $acquisitionDate,
                'cost_minor' => $costMinor,
                'residual_minor' => $residual,
                'useful_life_months' => $life,
                'method' => $method,
                'source_bill_id' => $sourceBillId,
                'location_id' => $data['locationId'] ?? null,
                'status' => 'active',
                'memo' => $data['memo'] ?? null,
                'created_by' => $actorId,
                'request_id' => $this->companyContext->requestId(),
            ]);
            $asset->save();

            $this->auditWriter->write(
                action: 'fixed_asset.capitalised',
                entityType: 'fixed_asset',
                entityId: $id,
                detail: ['asset_no' => $assetNo, 'cost_minor' => $costMinor],
            );

            return [
                'id' => $id,
                'assetNo' => $assetNo,
                'name' => $name,
                'assetClassId' => $assetClassId,
                'acquisitionDate' => $acquisitionDate,
                'costMinor' => $costMinor,
                'residualMinor' => $residual,
                'usefulLifeMonths' => $life,
                'method' => $method,
                'status' => 'active',
                'sourceBillId' => $sourceBillId,
            ];
        });
    }

    private function allocateAssetNo(string $companyId): string
    {
        $seq = NumberSequence::query()
            ->where('company_id', $companyId)
            ->where('doc_type', 'fixed_asset')
            ->lockForUpdate()
            ->first();

        if ($seq === null) {
            $seq = new NumberSequence([
                'id' => Uuid::v7(),
                'company_id' => $companyId,
                'doc_type' => 'fixed_asset',
                'fiscal_year' => (int) date('Y'),
                'next_value' => 1,
            ]);
            $seq->save();
        }

        $n = (int) $seq->next_value;
        $seq->next_value = $n + 1;
        $seq->save();

        return sprintf('FA-%06d', $n);
    }
}
