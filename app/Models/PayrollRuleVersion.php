<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PayrollRuleVersion extends Model
{
    use BelongsToCompany;

    protected $table = 'payroll_rule_version';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'rules' => 'array',
            'approved_at' => 'datetime',
        ];
    }
}
