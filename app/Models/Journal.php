<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * General-ledger journal header.
 *
 * Thin model — no business rules. Ledger module owns writes.
 * Posted journals are immutable (DB triggers).
 */
class Journal extends Model
{
    use BelongsToCompany;

    protected $table = 'journal';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'journal_no',
        'posting_date',
        'description',
        'source_type',
        'source_id',
        'event_type',
        'posting_key',
        'status',
        'prepared_by',
        'posted_by',
        'posted_at',
        'reverses_journal_id',
        'reversed_by_journal_id',
        'currency',
        'request_id',
    ];

    protected function casts(): array
    {
        return [
            'posting_date' => 'date',
            'posted_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'journal_id')->orderBy('line_no');
    }

    public function preparedByUser(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'prepared_by');
    }

    public function postedByUser(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'posted_by');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_journal_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_journal_id');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }
}
