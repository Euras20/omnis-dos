<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Requisition extends Model
{
    use BelongsToCompany;

    protected $table = 'requisition';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'req_no',
        'requested_by',
        'request_date',
        'status',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'reject_reason',
        'converted_purchase_order_id',
        'preferred_supplier_id',
        'memo',
        'request_id',
    ];

    protected function casts(): array
    {
        return [
            'request_date' => 'date',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RequisitionLine::class, 'requisition_id')->orderBy('line_no');
    }

    public function preferredSupplier(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'preferred_supplier_id');
    }

    public function convertedPurchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'converted_purchase_order_id');
    }
}
