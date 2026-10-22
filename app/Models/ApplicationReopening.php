<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationReopening extends BaseModel
{
    protected $casts = ['sections' => 'array', 'reopened_until' => 'datetime', 'resubmitted_at' => 'datetime'];

    public function reopener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }
}
