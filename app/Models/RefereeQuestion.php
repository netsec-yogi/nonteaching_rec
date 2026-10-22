<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RefereeQuestion extends BaseModel
{
    use SoftDeletes;

    public const TYPES = ['yes_no', 'rating', 'choice', 'short_text', 'long_text'];

    protected $casts = ['options' => 'array', 'is_required' => 'boolean'];

    public function template(): BelongsTo
    {
        return $this->belongsTo(RefereeFormTemplate::class, 'referee_form_template_id');
    }
}
