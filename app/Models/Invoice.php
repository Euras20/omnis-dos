<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use BelongsToCompany;

    protected $table = 'invoice';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'invoice_no',
        'customer_id',
        'sales_order_id',
        'invoice_date',
        'due_date',
        'currency',
        'fx_rate',
        'net_minor',
        'vat_minor',
        'nhil_minor',
        'getfund_minor',
        'covid_minor',
        'tax_total_minor',
        'gross_minor',
        'tax_rule_version_id',
        'tax_snapshot',
        'status',
        'journal_id',
        'memo',
        'created_by',
        'request_id',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'fx_rate' => 'decimal:8',
            'net_minor' => 'integer',
            'vat_minor' => 'integer',
            'nhil_minor' => 'integer',
            'getfund_minor' => 'integer',
            'covid_minor' => 'integer',
            'tax_total_minor' => 'integer',
            'gross_minor' => 'integer',
            'tax_snapshot' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'customer_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class, 'invoice_id')->orderBy('line_no');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class, 'invoice_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ReceiptAllocation::class, 'invoice_id');
    }
}
