<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Advertisement extends BaseModel
{
    use SoftDeletes;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const SUSPENDED = 'suspended';

    public const CLOSED = 'closed';

    public const ARCHIVED = 'archived';

    public const STATUSES = [self::DRAFT, self::PUBLISHED, self::SUSPENDED, self::CLOSED, self::ARCHIVED];

    /** Allowed status transitions. */
    public const TRANSITIONS = [
        self::DRAFT => [self::PUBLISHED, self::ARCHIVED],
        self::PUBLISHED => [self::SUSPENDED, self::CLOSED],
        self::SUSPENDED => [self::PUBLISHED, self::CLOSED],
        self::CLOSED => [self::PUBLISHED, self::ARCHIVED],
        self::ARCHIVED => [],
    ];

    public const ENDORSEMENT_MODES = ['not_applicable', 'optional', 'required_for_in_service'];

    protected $attributes = [
        'status' => self::DRAFT,
        'referees_required' => 3,
        'referee_deadline_days' => 14,
        'referee_max_reminders' => 3,
        'endorsement_mode' => 'required_for_in_service',
        'committee_evaluation_mode' => 'individual',
    ];

    protected function casts(): array
    {
        return [
            'publication_date' => 'date',
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
            'retention_due_at' => 'datetime',
            'disposed_at' => 'datetime',
            'fee_schedule' => 'array',
            'payment_methods' => 'array',
            'referee_reminder_days' => 'array',
            'referee_recommendations_mandatory' => 'boolean',
            'referee_extra_verification' => 'boolean',
            'allow_withdrawal' => 'boolean',
            'allow_clarification_uploads' => 'boolean',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(RecruitmentCycle::class, 'recruitment_cycle_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class)->orderBy('sort_order');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AdvertisementDocument::class);
    }

    public function documentRequirements(): HasMany
    {
        return $this->hasMany(DocumentRequirement::class);
    }

    public function scrutinyStages(): HasMany
    {
        return $this->hasMany(ScrutinyStage::class)->orderBy('sequence');
    }

    public function scrutinyTeams(): HasMany
    {
        return $this->hasMany(ScrutinyTeam::class);
    }

    public function committees(): HasMany
    {
        return $this->hasMany(Committee::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function administrators(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function refereeFormTemplate(): BelongsTo
    {
        return $this->belongsTo(RefereeFormTemplate::class);
    }

    public function evaluationFormTemplate(): BelongsTo
    {
        return $this->belongsTo(EvaluationFormTemplate::class);
    }

    public function checklistAssignments(): HasMany
    {
        return $this->hasMany(ChecklistAssignment::class);
    }

    public function scopeVisibleToPublic(Builder $query): Builder
    {
        return $query->whereIn('status', [self::PUBLISHED, self::CLOSED]);
    }

    /** Accepting new submissions right now. */
    public function isOpen(): bool
    {
        return $this->status === self::PUBLISHED
            && now()->between($this->opens_at, $this->closes_at);
    }
}
