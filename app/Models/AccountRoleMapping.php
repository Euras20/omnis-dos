<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Maps a posting role (CUSTOMER_AR, BANK_CASH, ...) to a client posting account.
 */
class AccountRoleMapping extends Model
{
    use BelongsToCompany;

    protected $table = 'account_role_mapping';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'role_key',
        'account_id',
        'updated_by',
        'updated_at',
    ];
}
