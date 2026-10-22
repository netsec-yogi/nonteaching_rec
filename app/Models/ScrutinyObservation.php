<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScrutinyObservation extends BaseModel
{
    protected $casts = ['is_deficiency' => 'boolean', 'resolved_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(ScrutinyStage::class, 'scrutiny_stage_id');
    }
}
