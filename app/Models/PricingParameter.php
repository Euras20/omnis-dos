<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasRowVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingParameter extends Model
{
    use BelongsToCompany;
    use HasRowVersion;

    protected $table = 'pricing_parameter';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'parameters',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'row_version' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * Default parameter set matching the frontend review build.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'supplierCur' => 'USD',
            'dhlCur' => 'USD',
            'volDivisor' => 5000,
            'dhlMin' => 45,
            'dutyBasis' => 'pct',
            'dutyPct' => 10,
            'dutyAmt' => 0,
            'insuranceBasis' => 'pct',
            'insurancePct' => 0.5,
            'insuranceAmt' => 0,
            'clearanceBasis' => 'pct',
            'clearancePct' => 2,
            'clearanceAmt' => 0,
            'markupBasis' => 'pct',
            'markupPct' => 35,
            'markupAmt' => 0,
            'contingencyBasis' => 'pct',
            'contingencyPct' => 5,
            'contingencyAmt' => 0,
            'extraCosts' => [
                ['name' => 'Local delivery to customer site', 'amount' => 500, 'per' => 'order'],
                ['name' => 'Port handling', 'amount' => 2, 'per' => 'unit'],
            ],
            'dhlBands' => [
                ['upTo' => 10, 'rate' => 12.5],
                ['upTo' => 30, 'rate' => 9.8],
                ['upTo' => 70, 'rate' => 8.1],
                ['upTo' => 300, 'rate' => 6.4],
                ['upTo' => null, 'rate' => 5.2],
            ],
        ];
    }
}
