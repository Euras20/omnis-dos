<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasRowVersion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Chart-of-accounts entry.
 *
 * Thin model — no business rules. Ledger module owns writes.
 */
class Account extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasRowVersion;

    protected $table = 'account';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'parent_id',
        'system_key',
        'code',
        'name',
        'description',
        'category',
        'detail_type',
        'kind',
        'node_type',
        'source',
        'currency',
        'is_active',
        'is_locked',
        'locked_by',
        'locked_at',
        'lock_reason',
        'fs_line_item',
        'fs_sub_line',
        'normal_balance',
        'is_contra',
        'statement',
        'report_section',
        'level',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_locked' => 'boolean',
            'is_contra' => 'boolean',
            'level' => 'integer',
            'row_version' => 'integer',
            'locked_at' => 'datetime',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'account_id');
    }
}
