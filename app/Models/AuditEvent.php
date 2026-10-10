<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only business audit row.
 *
 * Prefer writing via Shared AuditWriter inside the command transaction.
 * This model is for read paths (GET /api/v1/audit) only.
 */
class AuditEvent extends Model
{
    use BelongsToCompany;

    protected $table = 'audit_event';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'company_id',
        'actor_id',
        'action',
        'entity_type',
        'entity_id',
        'request_id',
        'detail',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
