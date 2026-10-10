<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasRowVersion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Legal company / tenant root.
 *
 * Thin model — no business rules. Period lock and identity live in Shared
 * services and the Identity module.
 */
class Company extends Model
{
    use HasFactory;
    use HasRowVersion;

    protected $table = 'company';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'code',
        'legal_name',
        'tin',
        'functional_currency',
        'fiscal_year_start_month',
        'timezone',
        'period_lock_date',
        'address',
        'phone',
        'email',
        'bank_details',
        'vat_number',
    ];

    protected function casts(): array
    {
        return [
            'period_lock_date' => 'date',
            'fiscal_year_start_month' => 'integer',
            'row_version' => 'integer',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(AppUser::class, 'company_id');
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class, 'company_id');
    }
}
