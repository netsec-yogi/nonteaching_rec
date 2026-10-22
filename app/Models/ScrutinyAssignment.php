<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScrutinyAssignment extends BaseModel
{
    protected $attributes = ['status' => 'assigned'];

    protected $casts = ['submitted_at' => 'datetime'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(ScrutinyStage::class, 'scrutiny_stage_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
