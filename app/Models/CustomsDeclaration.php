<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasRowVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomsDeclaration extends Model
{
    use BelongsToCompany;
    use HasRowVersion;

    protected $table = 'customs_declaration';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'declaration_no',
        'shipment_id',
        'consignment_id',
        'jurisdiction',
        'broker_id',
        'hs_code',
        'valuation',
        'duties_minor',
        'recoverable_tax_minor',
        'nonrecoverable_minor',
        'inspection',
        'status',
        'payment',
        'release_date',
        'free_time_end',
        'demurrage_minor',
        'docs',
        'row_version',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'duties_minor' => 'integer',
            'recoverable_tax_minor' => 'integer',
            'nonrecoverable_minor' => 'integer',
            'demurrage_minor' => 'integer',
            'release_date' => 'date',
            'free_time_end' => 'date',
            'docs' => 'array',
            'row_version' => 'integer',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id');
    }

    public function consignment(): BelongsTo
    {
        return $this->belongsTo(Consignment::class, 'consignment_id');
    }

    public function broker(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'broker_id');
    }
}
