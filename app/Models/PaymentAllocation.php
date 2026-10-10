<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAllocation extends Model
{
    use BelongsToCompany;

    protected $table = 'payment_allocation';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'company_id',
        'supplier_payment_id',
        'bill_id',
        'amount_minor',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
        ];
    }

    public function supplierPayment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class, 'supplier_payment_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class, 'bill_id');
    }
}
