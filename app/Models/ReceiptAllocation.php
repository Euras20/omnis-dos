<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceiptAllocation extends Model
{
    use BelongsToCompany;

    protected $table = 'receipt_allocation';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'company_id',
        'receipt_id',
        'invoice_id',
        'amount_minor',
        'allocated_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'allocated_at' => 'date',
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class, 'receipt_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }
}
