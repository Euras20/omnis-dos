<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepreciationLine extends Model
{
    use BelongsToCompany;

    protected $table = 'depreciation_line';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'charge_minor' => 'integer',
            'accum_before_minor' => 'integer',
            'accum_after_minor' => 'integer',
            'nbv_after_minor' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(DepreciationRun::class, 'depreciation_run_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }
}
