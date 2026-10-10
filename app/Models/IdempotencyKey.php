<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Stored outcome of an idempotent command.
 * Prefer Shared IdempotencyStore for read/write.
 */
class IdempotencyKey extends Model
{
    use BelongsToCompany;

    protected $table = 'idempotency_key';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'company_id',
        'key',
        'request_hash',
        'status_code',
        'response',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'response' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
