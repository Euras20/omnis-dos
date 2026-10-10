<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasRowVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhtRuleVersion extends Model
{
    use BelongsToCompany;
    use HasRowVersion;

    protected $table = 'wht_rule_version';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'code',
        'name',
        'effective_from',
        'effective_to',
        'rate_percent',
        'applies_to',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'rate_percent' => 'decimal:4',
            'is_active' => 'boolean',
            'row_version' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
