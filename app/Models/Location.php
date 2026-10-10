<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasRowVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Location extends Model
{
    use BelongsToCompany;
    use HasRowVersion;

    protected $table = 'location';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'code',
        'name',
        'warehouse',
        'loc_type',
        'keeper_id',
        'is_assignable',
        'capacity',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_assignable' => 'boolean',
            'is_active' => 'boolean',
            'capacity' => 'integer',
            'row_version' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function keeper(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'keeper_id');
    }
}
