<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasRowVersion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Named role holding a set of permission codes.
 */
class Role extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasRowVersion;

    protected $table = 'role';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'code',
        'name',
        'description',
        'approval_limit_minor',
        'is_system',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'approval_limit_minor' => 'integer',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'row_version' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class, 'role_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            AppUser::class,
            'user_role',
            'role_id',
            'user_id',
        )->withTimestamps();
    }
}
