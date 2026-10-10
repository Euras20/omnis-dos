<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot: role ↔ permission code (string).
 * Composite primary key (role_id, permission) — no surrogate id.
 */
class RolePermission extends Model
{
    protected $table = 'role_permission';

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = null;

    protected $fillable = [
        'role_id',
        'permission',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }
}
