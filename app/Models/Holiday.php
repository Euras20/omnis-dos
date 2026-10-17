<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    use BelongsToCompany;

    protected $table = 'holiday';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'holiday_date' => 'date',
            'year' => 'integer',
        ];
    }
}
