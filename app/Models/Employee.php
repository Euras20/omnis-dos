<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasRowVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Employee extends Model
{
    use BelongsToCompany;
    use HasRowVersion;

    protected $table = 'employee';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'hire_date' => 'date',
            'termination_date' => 'date',
            'basic_minor' => 'integer',
            'is_active' => 'boolean',
            'row_version' => 'integer',
        ];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'party_id');
    }
}
