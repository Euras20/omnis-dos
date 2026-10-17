<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PeriodClose extends Model
{
    use BelongsToCompany;

    protected $table = 'period_close';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'locked_through' => 'date',
            'checks_snapshot' => 'array',
            'waived_count' => 'integer',
            'locked_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }
}
