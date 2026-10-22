<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClarificationResponse extends BaseModel
{
    public const UPDATED_AT = null;

    public function request(): BelongsTo
    {
        return $this->belongsTo(ClarificationRequest::class, 'clarification_request_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ApplicationDocument::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
