<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExportLog extends BaseModel
{
    public const UPDATED_AT = null;

    protected $casts = ['filters' => 'array', 'is_sensitive' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
