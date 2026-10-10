<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PerformanceLayout extends Model
{
    use BelongsToCompany;

    protected $table = 'performance_layout';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'layout' => 'array',
        ];
    }
}
