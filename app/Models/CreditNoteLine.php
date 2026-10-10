<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditNoteLine extends Model
{
    use BelongsToCompany;

    protected $table = 'credit_note_line';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'credit_note_id',
        'invoice_line_id',
        'line_no',
        'description',
        'qty',
        'net_minor',
        'vat_minor',
        'nhil_minor',
        'getfund_minor',
        'covid_minor',
        'gross_minor',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'net_minor' => 'integer',
            'vat_minor' => 'integer',
            'nhil_minor' => 'integer',
            'getfund_minor' => 'integer',
            'covid_minor' => 'integer',
            'gross_minor' => 'integer',
        ];
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class, 'credit_note_id');
    }
}
