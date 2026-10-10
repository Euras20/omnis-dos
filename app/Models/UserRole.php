<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Assignment of a role to a user (with optional validity window).
 */
class UserRole extends Model
{
    use BelongsToCompany;

    protected $table = 'user_role';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'user_id',
        'role_id',
        'valid_from',
        'valid_to',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'user_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }
}
