<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Application extends BaseModel
{
    use SoftDeletes;

    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const REOPENED = 'reopened';

    public const WITHDRAWN = 'withdrawn';

    public const SCRUTINY_STATUSES = [
        'submitted', 'received', 'assigned', 'in_progress', 'clarification_required',
        'clarification_received', 'completed', 'under_review', 'decided',
    ];

    public const OUTCOMES = ['recommended', 'incomplete', 'not_recommended'];

    public const COMMITTEE_STATUSES = ['pending', 'recommended', 'not_recommended', 'further_review'];

    public const FEE_STATUSES = ['not_submitted', 'pending_verification', 'verified', 'rejected', 'exempt'];

    public const ENDORSEMENT_STATUSES = ['not_applicable', 'pending', 'submitted', 'verified', 'rejected'];

    public const REFEREE_STATUSES = ['not_started', 'pending', 'partial', 'complete', 'exception'];

    public const DOCUMENT_STATUSES = ['pending', 'verified', 'deficient'];

    /** Fields mirrored from column defaults so a fresh model matches a reloaded one. */
    protected $attributes = [
        'status' => self::DRAFT,
        'fee_status' => 'not_submitted',
        'endorsement_status' => 'not_applicable',
        'referee_status' => 'not_started',
        'document_status' => 'pending',
        'is_in_service' => false,
        'referee_email_exception' => false,
        'referee_requirement_waived' => false,
        'version' => 0,
    ];

    protected function casts(): array
    {
        return [
            'completed_steps' => 'array',
            'application_date' => 'date',
            'declaration_date' => 'date',
            'declaration_accepted_at' => 'datetime',
            'referees_confirmed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'first_submitted_at' => 'datetime',
            'reopened_until' => 'datetime',
            'withdrawn_at' => 'datetime',
            'is_in_service' => 'boolean',
            'has_employment' => 'boolean',
            'has_merit_records' => 'boolean',
            'has_training_records' => 'boolean',
            'has_memberships' => 'boolean',
            'referee_email_exception' => 'boolean',
            'referee_requirement_waived' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Application $application) {
            $application->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // ---------------------------------------------------------------- state

    /** Whether the candidate may change the form right now. */
    public function isEditableByApplicant(): bool
    {
        if ($this->status === self::DRAFT) {
            return true;
        }

        return $this->status === self::REOPENED
            && ($this->reopened_until === null || $this->reopened_until->isFuture());
    }

    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null && in_array($this->status, [self::SUBMITTED, self::REOPENED], true);
    }

    // -------------------------------------------------------- relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function advertisement(): BelongsTo
    {
        return $this->belongsTo(Advertisement::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function personal(): HasOne
    {
        return $this->hasOne(ApplicationPersonalDetail::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(ApplicationAddress::class);
    }

    public function qualifications(): HasMany
    {
        return $this->hasMany(Qualification::class)->orderBy('sort_order');
    }

    public function employments(): HasMany
    {
        return $this->hasMany(Employment::class)->orderBy('sort_order');
    }

    public function meritRecords(): HasMany
    {
        return $this->hasMany(MeritRecord::class)->orderBy('sort_order');
    }

    public function trainings(): HasMany
    {
        return $this->hasMany(Training::class)->orderBy('sort_order');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class)->orderBy('sort_order');
    }

    public function declarations(): HasMany
    {
        return $this->hasMany(ApplicationDeclaration::class);
    }

    public function referees(): HasMany
    {
        return $this->hasMany(Referee::class)->orderBy('position');
    }

    public function refereeInvitations(): HasMany
    {
        return $this->hasMany(RefereeInvitation::class);
    }

    public function refereeResponses(): HasMany
    {
        return $this->hasMany(RefereeResponse::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function endorsement(): HasOne
    {
        return $this->hasOne(EmployerEndorsement::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ApplicationDocument::class);
    }

    public function checklistEntries(): HasMany
    {
        return $this->hasMany(ApplicationChecklistEntry::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ApplicationVersion::class)->orderByDesc('version');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(ApplicationStatusHistory::class)->orderBy('id');
    }

    public function reopenings(): HasMany
    {
        return $this->hasMany(ApplicationReopening::class);
    }

    public function scrutinyAssignments(): HasMany
    {
        return $this->hasMany(ScrutinyAssignment::class);
    }

    public function scrutinyResults(): HasMany
    {
        return $this->hasMany(ScrutinyItemResult::class);
    }

    public function scrutinyReviews(): HasMany
    {
        return $this->hasMany(ScrutinyReview::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(ScrutinyObservation::class);
    }

    public function clarificationRequests(): HasMany
    {
        return $this->hasMany(ClarificationRequest::class);
    }

    public function committeeAssignments(): HasMany
    {
        return $this->hasMany(CommitteeAssignment::class);
    }

    public function committeeEvaluations(): HasMany
    {
        return $this->hasMany(CommitteeEvaluation::class);
    }

    public function committeeDecisions(): HasMany
    {
        return $this->hasMany(CommitteeDecision::class);
    }

    public function currentStage(): BelongsTo
    {
        return $this->belongsTo(ScrutinyStage::class, 'current_stage_id');
    }

    public function declaration(string $code): ?ApplicationDeclaration
    {
        return $this->declarations->firstWhere('code', $code);
    }

    public function address(string $type): ?ApplicationAddress
    {
        return $this->addresses->firstWhere('type', $type);
    }
}
