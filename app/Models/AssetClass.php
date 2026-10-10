<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasRowVersion;
use Illuminate\Database\Eloquent\Model;

class AssetClass extends Model
{
    use BelongsToCompany;
    use HasRowVersion;

    protected $table = 'asset_class';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'useful_life_months' => 'integer',
            'residual_pct' => 'integer',
            'is_active' => 'boolean',
            'row_version' => 'integer',
        ];
    }
}
