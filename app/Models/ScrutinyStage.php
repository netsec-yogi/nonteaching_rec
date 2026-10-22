<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScrutinyStage extends BaseModel
{
    protected $casts = ['is_decision_stage' => 'boolean', 'allow_same_reviewer' => 'boolean'];

    public function advertisement(): BelongsTo
    {
        return $this->belongsTo(Advertisement::class);
    }
}
