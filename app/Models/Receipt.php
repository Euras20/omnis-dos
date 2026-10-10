<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Receipt extends Model
{
    use BelongsToCompany;

    protected $table = 'receipt';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'receipt_no',
        'customer_id',
        'receipt_date',
        'currency',
        'cash_account_code',
        'cash_minor',
        'wht_minor',
        'wht_certificate',
        'total_minor',
        'bank_ref',
        'memo',
        'journal_id',
        'created_by',
        'request_id',
    ];

    protected function casts(): array
    {
        return [
            'receipt_date' => 'date',
            'cash_minor' => 'integer',
            'wht_minor' => 'integer',
            'total_minor' => 'integer',
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

    public function allocations(): HasMany
    {
        return $this->hasMany(ReceiptAllocation::class, 'receipt_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(CustomerRefund::class, 'receipt_id');
    }
}
