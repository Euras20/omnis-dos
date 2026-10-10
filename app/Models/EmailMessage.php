<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailMessage extends Model
{
    use BelongsToCompany;

    protected $table = 'email_message';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function documentFile(): BelongsTo
    {
        return $this->belongsTo(DocumentFile::class, 'document_file_id');
    }
}
