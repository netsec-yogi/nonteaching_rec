<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChecklistItem extends BaseModel
{
    use SoftDeletes;

    public const SECTIONS = [
        'personal', 'fee', 'qualification', 'employment', 'category', 'disability',
        'ex_serviceman', 'endorsement', 'referee', 'declaration', 'identity', 'other',
    ];

    protected $casts = [
        'is_mandatory' => 'boolean',
        'requires_reason_if_not_verified' => 'boolean',
        'applicant_visible' => 'boolean',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class, 'checklist_template_id');
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }
}
