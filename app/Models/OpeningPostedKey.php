<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpeningPostedKey extends Model
{
    protected $table = 'opening_posted_key';

    protected $primaryKey = 'posted_key';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
        ];
    }
}
