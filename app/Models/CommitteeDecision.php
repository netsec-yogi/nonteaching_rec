<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommitteeDecision extends BaseModel
{
    protected $casts = ['responses' => 'array', 'decided_at' => 'datetime'];

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function committee(): BelongsTo
    {
        return $this->belongsTo(Committee::class);
    }
}
