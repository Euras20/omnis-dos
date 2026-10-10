<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierPayment extends Model
{
    use BelongsToCompany;

    protected $table = 'supplier_payment';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'payment_no',
        'supplier_id',
        'payment_date',
        'status',
        'cash_account_code',
        'gross_minor',
        'wht_minor',
        'net_minor',
        'bank_ref',
        'prepared_by',
        'confirmed_by',
        'confirmed_at',
        'journal_id',
        'memo',
        'request_id',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'gross_minor' => 'integer',
            'wht_minor' => 'integer',
            'net_minor' => 'integer',
            'confirmed_at' => 'datetime',
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
        return $this->hasMany(PaymentAllocation::class, 'supplier_payment_id');
    }
}
