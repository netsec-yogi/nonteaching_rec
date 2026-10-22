<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommitteeEvaluation extends BaseModel
{
    protected $attributes = ['status' => 'draft'];

    protected $casts = ['responses' => 'array', 'submitted_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function committee(): BelongsTo
    {
        return $this->belongsTo(Committee::class);
    }
}
