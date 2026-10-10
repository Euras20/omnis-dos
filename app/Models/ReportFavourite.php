<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ReportFavourite extends Model
{
    use BelongsToCompany;

    protected $table = 'report_favourite';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
