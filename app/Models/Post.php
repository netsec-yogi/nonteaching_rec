<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Post extends BaseModel
{
    use SoftDeletes;

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return [
            'age_cutoff_date' => 'date',
            'fee_schedule' => 'array',
        ];
    }

    public function advertisement(): BelongsTo
    {
        return $this->belongsTo(Advertisement::class);
    }

    public function vacancies(): HasMany
    {
        return $this->hasMany(PostVacancy::class);
    }

    public function eligibilityCriteria(): HasMany
    {
        return $this->hasMany(PostEligibilityCriterion::class)->orderBy('sort_order');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function effectiveEndorsementMode(): string
    {
        return $this->endorsement_mode ?: $this->advertisement->endorsement_mode;
    }

    public function effectiveFeeSchedule(): array
    {
        return $this->fee_schedule ?: ($this->advertisement->fee_schedule ?? ['default' => 0, 'exemptions' => []]);
    }

    public function isAcceptingApplications(): bool
    {
        return $this->status === 'open' && $this->advertisement->isOpen();
    }
}
