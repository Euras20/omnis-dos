<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Restricts a user to specific locations (stock / warehouse staff).
 * Empty set = all locations. location_id FK added when Catalog lands.
 */
class UserLocationScope extends Model
{
    use BelongsToCompany;

    protected $table = 'user_location_scope';

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = null;

    protected $fillable = [
        'company_id',
        'user_id',
        'location_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'user_id');
    }
}
