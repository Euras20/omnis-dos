<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user grant or deny that overrides role permissions.
 * Deny always wins (PermissionChecker).
 */
class UserPermissionOverride extends Model
{
    use BelongsToCompany;

    protected $table = 'user_permission_override';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'user_id',
        'permission',
        'effect',
        'reason',
        'created_by',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'user_id');
    }
}
