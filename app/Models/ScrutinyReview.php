<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScrutinyReview extends BaseModel
{
    protected $attributes = ['status' => 'draft'];

    protected $casts = ['submitted_at' => 'datetime'];

    public function stage(): BelongsTo
    {
        return $this->belongsTo(ScrutinyStage::class, 'scrutiny_stage_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
