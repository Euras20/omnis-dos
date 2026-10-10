<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasRowVersion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Customer, supplier, carrier or broker master.
 *
 * Thin model — no business rules. Catalog module owns writes.
 */
class Party extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasRowVersion;

    protected $table = 'party';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'code',
        'name',
        'party_type',
        'tin',
        'reg_no',
        'city',
        'region',
        'country',
        'address',
        'gps',
        'contact_name',
        'email',
        'phone',
        'payment_terms',
        'credit_limit_minor',
        'currency',
        'vat_registered',
        'wht_rule_code',
        'account_owner',
        'is_main_supplier',
        'contacts',
        'bank_details',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit_minor' => 'integer',
            'vat_registered' => 'boolean',
            'is_main_supplier' => 'boolean',
            'is_active' => 'boolean',
            'contacts' => 'array',
            'bank_details' => 'array',
            'row_version' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
