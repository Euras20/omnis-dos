<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditNote extends Model
{
    use BelongsToCompany;

    protected $table = 'credit_note';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'credit_no',
        'invoice_id',
        'credit_date',
        'currency',
        'net_minor',
        'vat_minor',
        'nhil_minor',
        'getfund_minor',
        'covid_minor',
        'tax_total_minor',
        'gross_minor',
        'reason',
        'journal_id',
        'created_by',
        'request_id',
    ];

    protected function casts(): array
    {
        return [
            'credit_date' => 'date',
            'net_minor' => 'integer',
            'vat_minor' => 'integer',
            'nhil_minor' => 'integer',
            'getfund_minor' => 'integer',
            'covid_minor' => 'integer',
            'tax_total_minor' => 'integer',
            'gross_minor' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CreditNoteLine::class, 'credit_note_id')->orderBy('line_no');
    }
}
