<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyBankAccount extends Model
{
    use BelongsToCompany;

    protected $table = 'company_bank_account';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'type',
        'gl_account_id',
        'opening_balance_doc_id',
        'opening_balance_date',
        'opening_balance_minor',
        'bank_name',
        'account_name',
        'account_number',
        'branch',
        'swift',
        'currency',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'gl_account_id');
    }
}
