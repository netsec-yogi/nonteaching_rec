<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Committee extends BaseModel
{
    public function advertisement(): BelongsTo
    {
        return $this->belongsTo(Advertisement::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'committee_members')->withPivot('is_chair')->withTimestamps();
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CommitteeAssignment::class);
    }

    public function evaluationForm(): BelongsTo
    {
        return $this->belongsTo(EvaluationFormTemplate::class, 'evaluation_form_template_id');
    }

    public function effectiveMode(): string
    {
        return $this->evaluation_mode ?: $this->advertisement->committee_evaluation_mode;
    }

    public function effectiveForm(): ?EvaluationFormTemplate
    {
        return $this->evaluationForm ?? $this->advertisement->evaluationFormTemplate;
    }
}
