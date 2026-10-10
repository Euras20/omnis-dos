<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DepreciationRun extends Model
{
    use BelongsToCompany;

    protected $table = 'depreciation_run';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'total_charge_minor' => 'integer',
            'posted_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DepreciationLine::class, 'depreciation_run_id');
    }
}
