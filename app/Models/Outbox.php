<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Transactional outbox row for side-effects.
 * Prefer Shared OutboxWriter / OutboxDispatcher for write and claim.
 */
class Outbox extends Model
{
    use BelongsToCompany;

    protected $table = 'outbox';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'company_id',
        'aggregate_type',
        'aggregate_id',
        'event_type',
        'payload',
        'status',
        'attempts',
        'available_at',
        'locked_at',
        'locked_by',
        'last_error',
        'processed_at',
        'request_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'locked_at' => 'datetime',
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
