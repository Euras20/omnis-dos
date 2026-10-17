<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class TaxFiling extends Model
{
    use BelongsToCompany;

    protected $table = 'tax_filing';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'revision' => 'integer',
        ];
    }
}
