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
        'code',
        'name',
        'category',
        'kind',
        'currency',
        'is_active',
        'fs_line_item',
        'fs_sub_line',
        'normal_balance',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'row_version' => 'integer',
        ];
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
