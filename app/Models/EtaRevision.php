<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EtaRevision extends Model
{
    use BelongsToCompany;

    protected $table = 'eta_revision';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'shipment_id',
        'sales_order_id',
        'original_promise',
        'previous',
        'revised',
        'source',
        'confidence',
        'reason',
        'revised_by',
        'revised_at',
    ];

    protected function casts(): array
    {
        return [
            'original_promise' => 'date',
            'previous' => 'date',
            'revised' => 'date',
            'revised_at' => 'datetime',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id');
    }
}
