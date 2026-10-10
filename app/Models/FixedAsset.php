<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasRowVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixedAsset extends Model
{
    use BelongsToCompany;
    use HasRowVersion;

    protected $table = 'fixed_asset';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'acquisition_date' => 'date',
            'cost_minor' => 'integer',
            'residual_minor' => 'integer',
            'useful_life_months' => 'integer',
            'disposal_date' => 'date',
            'disposal_proceeds_minor' => 'integer',
            'row_version' => 'integer',
        ];
    }

    public function assetClass(): BelongsTo
    {
        return $this->belongsTo(AssetClass::class, 'asset_class_id');
    }
}
