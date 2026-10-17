<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\PricingParameter;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * PUT /api/v1/pricing/parameters — save company pricing parameters.
 */
final class UpsertPricingParameters
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
        $this->permissionChecker->assertCan($actorId, 'sales.quotes.manage');

        $companyId = $this->companyContext->companyId();
        $requestId = $this->companyContext->requestId();

        $parameters = $this->normalise($data);

        return DB::transaction(function () use ($companyId, $parameters, $actorId, $requestId) {
            $existing = PricingParameter::query()
                ->where('company_id', $companyId)
                ->first();

            if ($existing === null) {
                $row = new PricingParameter;
                $row->id = Uuid::v7();
                $row->company_id = $companyId;
                $row->parameters = $parameters;
                $row->save();
            } else {
                $existing->parameters = $parameters;
                $existing->save();
                $row = $existing;
            }

            $this->auditWriter->write(
                'pricing.parameters.updated',
                'pricing_parameter',
                $row->id,
                ['companyId' => $companyId],
            );

            return $this->toArray($row);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data): array
    {
        $defaults = PricingParameter::defaults();

        $out = $defaults;

        $stringKeys = ['supplierCur', 'dhlCur'];
        foreach ($stringKeys as $k) {
            if (isset($data[$k]) && is_string($data[$k]) && $data[$k] !== '') {
                $out[$k] = strtoupper($data[$k]);
            }
        }

        $floatKeys = [
            'volDivisor', 'dhlMin',
            'dutyPct', 'dutyAmt', 'insurancePct', 'insuranceAmt',
            'clearancePct', 'clearanceAmt', 'markupPct', 'markupAmt',
            'contingencyPct', 'contingencyAmt',
        ];
        foreach ($floatKeys as $k) {
            if (isset($data[$k]) && is_numeric($data[$k])) {
                $value = (float) $data[$k];
                if ($value < 0) {
                    throw new BusinessRuleException("{$k} must be 0 or more.");
                }
                $out[$k] = $value;
            }
        }

        if (isset($out['volDivisor']) && $out['volDivisor'] <= 0) {
            throw new BusinessRuleException('volDivisor must be above 0.');
        }

        $basisKeys = ['dutyBasis', 'insuranceBasis', 'clearanceBasis', 'markupBasis', 'contingencyBasis'];
        foreach ($basisKeys as $k) {
            if (isset($data[$k]) && in_array($data[$k], ['pct', 'amt'], true)) {
                $out[$k] = $data[$k];
            }
        }

        if (isset($data['extraCosts']) && is_array($data['extraCosts'])) {
            $extras = [];
            foreach ($data['extraCosts'] as $extra) {
                if (! is_array($extra)) {
                    continue;
                }
                $name = trim((string) ($extra['name'] ?? ''));
                $amount = is_numeric($extra['amount'] ?? null) ? (float) $extra['amount'] : 0.0;
                $per = ($extra['per'] ?? 'unit') === 'order' ? 'order' : 'unit';
                if ($name === '') {
                    continue;
                }
                if ($amount < 0) {
                    throw new BusinessRuleException('Extra cost amount must be 0 or more.');
                }
                $extras[] = ['name' => $name, 'amount' => $amount, 'per' => $per];
            }
            $out['extraCosts'] = $extras;
        }

        if (isset($data['dhlBands']) && is_array($data['dhlBands'])) {
            $bands = [];
            foreach ($data['dhlBands'] as $band) {
                if (! is_array($band)) {
                    continue;
                }
                $upTo = $band['upTo'] ?? null;
                $upTo = ($upTo === null || $upTo === '') ? null : (is_numeric($upTo) ? (float) $upTo : null);
                $rate = is_numeric($band['rate'] ?? null) ? (float) $band['rate'] : 0.0;
                if ($rate <= 0) {
                    throw new BusinessRuleException('Each DHL band needs a rate above 0.');
                }
                $bands[] = ['upTo' => $upTo, 'rate' => $rate];
            }
            for ($i = 1; $i < count($bands); $i++) {
                $prev = $bands[$i - 1]['upTo'];
                $curr = $bands[$i]['upTo'];
                if ($prev !== null && $curr !== null && $curr <= $prev) {
                    throw new BusinessRuleException('DHL bands must increase in weight.');
                }
            }
            $out['dhlBands'] = $bands;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(PricingParameter $row): array
    {
        return [
            'id' => $row->id,
            'companyId' => $row->company_id,
            'parameters' => $row->parameters ?? PricingParameter::defaults(),
            'rowVersion' => $row->row_version,
            'updatedAt' => $row->updated_at?->toIso8601String(),
        ];
    }
}
