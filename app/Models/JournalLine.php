<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Journal line (debit or credit).
 *
 * Thin model — no business rules. High-volume append-only (bigserial PK).
 */
class JournalLine extends Model
{
    use BelongsToCompany;

    protected $table = 'journal_line';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'company_id',
        'journal_id',
        'line_no',
        'account_id',
        'debit_minor',
        'credit_minor',
        'party_id',
        'memo',
        'dimension1',
        'dimension2',
    ];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'debit_minor' => 'integer',
            'credit_minor' => 'integer',
        ];
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'party_id');
    }
}
