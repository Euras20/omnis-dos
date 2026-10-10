<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class CloseWaiver extends Model
{
    use BelongsToCompany;

    protected $table = 'close_waiver';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'waived_at' => 'datetime',
        ];
    }
}
