<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class SavedReport extends Model
{
    use BelongsToCompany;

    protected $table = 'saved_report';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_shared' => 'boolean',
        ];
    }
}
