<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerRefund extends Model
{
    use BelongsToCompany;

    protected $table = 'customer_refund';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'receipt_id',
        'refund_no',
        'refund_date',
        'amount_minor',
        'cash_account_code',
        'bank_ref',
        'journal_id',
        'created_by',
        'request_id',
    ];

    protected function casts(): array
    {
        return [
            'refund_date' => 'date',
            'amount_minor' => 'integer',
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class, 'receipt_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_id');
    }
}
