<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bill extends Model
{
    use BelongsToCompany;

    protected $table = 'bill';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'bill_no',
        'supplier_id',
        'supplier_ref',
        'purchase_order_id',
        'bill_date',
        'due_date',
        'currency',
        'net_minor',
        'tax_minor',
        'gross_minor',
        'expense_account_id',
        'status',
        'journal_id',
        'memo',
        'created_by',
        'request_id',
    ];

    protected function casts(): array
    {
        return [
            'bill_date' => 'date',
            'due_date' => 'date',
            'net_minor' => 'integer',
            'tax_minor' => 'integer',
            'gross_minor' => 'integer',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'supplier_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'bill_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BillLine::class, 'bill_id')->orderBy('line_no');
    }
}
