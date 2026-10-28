<?php

namespace App\Services\ApplicationForm;

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\DocumentType;
use App\Models\Employment;
use App\Models\Post;
use App\Models\Qualification;
use App\Models\Training;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FeeCalculator;
use App\Services\RequirementResolver;
use App\Services\SettingsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ApplicationFormService
{
    public const RELATIONS = [
        'user', 'advertisement', 'post.advertisement', 'personal', 'addresses', 'qualifications',
        'employments', 'meritRecords', 'trainings', 'memberships', 'declarations', 'referees',
        'payment', 'endorsement', 'documents.documentType', 'checklistEntries', 'reopenings',
    ];

    public function __construct(
        private SectionRegistry $sections,
        private RequirementResolver $requirements,
        private FeeCalculator $fees,
        private SettingsService $settings,
        private AuditLogger $audit,
    ) {}

    public function load(Application $application): Application
    {
        return $application->load(self::RELATIONS);
    }

    public function create(User $user, Post $post): Application
    {
        $post->loadMissing('advertisement');
        if (! $post->isAcceptingApplications()) {
            throw ValidationException::withMessages(['post_id' => 'This post is not accepting applications.']);
        }
        if (Application::where('user_id', $user->id)->where('post_id', $post->id)->exists()) {
            throw ValidationException::withMessages(['post_id' => 'You already have an application for this post.']);
        }
        $openDrafts = Application::where('user_id', $user->id)->where('status', Application::DRAFT)->count();
        if ($openDrafts >= (int) $this->settings->get('applicant.max_open_drafts')) {
            throw ValidationException::withMessages(['post_id' => 'You have too many applications in progress. Submit or withdraw one first.']);
        }

        return DB::transaction(function () use ($user, $post) {
            $application = Application::create([
                'user_id' => $user->id,
                'advertisement_id' => $post->advertisement_id,
                'post_id' => $post->id,
                'status' => Application::DRAFT,
                'current_step' => 'payment',
                'completed_steps' => [],
            ]);
            $application->personal()->create([
                'full_name' => mb_strtoupper($user->name),
                'email' => $user->email,
                'mobile' => $user->mobile,
                'nationality' => 'Indian',
            ]);
            $this->audit->record('application.created', $application, [], ['post' => $post->code]);

            return $application;
        });
    }

    /** Validate and save one wizard step, returning the refreshed payload. */
    public function saveSection(Application $application, string $key, array $input): array
    {
        if (! $this->sections->has($key)) {
            abort(404, 'Unknown section.');
        }
        $this->assertEditable($application, $key);
        $this->load($application);

        $section = $this->sections->get($key);
        $validator = Validator::make($input, $section->rules($application), $section->messages(), $section->attributes());
        if ($after = $section->after($application)) {
            $validator->after($after);
        }
        $data = $validator->validate();

        DB::transaction(function () use ($application, $section, $data, $key) {
            $section->save($application, $data);
            $steps = array_values(array_unique([...($application->completed_steps ?? []), $key]));
            $application->forceFill(['current_step' => $key, 'completed_steps' => $steps])->save();
            $this->audit->record('application.section_saved', $application, [], [], ['section' => $key]);
        });

        return $this->payload($application->fresh());
    }

    public function assertEditable(Application $application, ?string $sectionKey = null): void
    {
        if (! $application->isEditableByApplicant()) {
            throw new AuthorizationException('This application has been submitted and can no longer be edited.');
        }

        if ($application->status === Application::REOPENED && $sectionKey) {
            $reopening = $application->reopenings()->latest('id')->first();
            $allowed = $reopening?->sections;
            if ($allowed && ! in_array($sectionKey, $allowed, true)) {
                throw new AuthorizationException('Only the sections opened for correction can be edited.');
            }
        }
    }

    /** @return array<string, list<string>> step key => blocking issues */
    public function issues(Application $application): array
    {
        $this->load($application);
        $issues = [];
        foreach ($this->sections->all() as $key => $section) {
            $issues[$key] = $section->isApplicable($application) ? $section->issues($application) : [];
        }

        return $issues;
    }

    public function payload(Application $application): array
    {
        $this->load($application);
        $issues = $this->issues($application);
        $reopening = $application->status === Application::REOPENED ? $application->reopenings->sortByDesc('id')->first() : null;

        return [
            'application' => [
                'uuid' => $application->uuid,
                'reference_no' => $application->reference_no,
                'status' => $application->status,
                'editable' => $application->isEditableByApplicant(),
                'editable_sections' => $reopening?->sections,
                'reopen_reason' => $reopening?->reason,
                'reopened_until' => $application->reopened_until?->toIso8601String(),
                'version' => $application->version,
                'submitted_at' => $application->submitted_at?->toIso8601String(),
                'current_step' => $application->current_step,
                'email_verified' => $application->user->hasVerifiedEmail(),
                'fee' => $this->fees->breakdown($application),
                'advertisement' => [
                    'id' => $application->advertisement->id,
                    'number' => $application->advertisement->number,
                    'title' => $application->advertisement->title,
                    'closes_at' => $application->advertisement->closes_at->toIso8601String(),
                    'is_open' => $application->advertisement->isOpen(),
                    'payment_methods' => $application->advertisement->payment_methods,
                    'payment_instructions' => $application->advertisement->payment_instructions,
                    'fee_rules_text' => $application->advertisement->fee_rules_text,
                    'referees_required' => $application->advertisement->referees_required,
                    'instructions' => $application->advertisement->instructions,
                ],
                'post' => $application->post->only(['id', 'code', 'name', 'department', 'pay_level', 'pay_scale']),
            ],
            'steps' => collect($this->sections->all())->map(fn ($s, $key) => [
                'key' => $key,
                'title' => $s->title(),
                'form_reference' => $s->formReference(),
                'applicable' => $s->isApplicable($application),
                'issues' => $issues[$key],
                'visited' => in_array($key, $application->completed_steps ?? [], true),
            ])->values()->all(),
            'data' => collect($this->sections->all())->map(fn ($s) => $s->data($application))->all(),
            'documents' => $application->documents->map(fn (ApplicationDocument $d) => $this->documentSummary($d))->values()->all(),
            'requirements' => $this->requirements->documentRequirements($application)->map(fn ($r) => [
                'document_type' => $r->documentType->code,
                'name' => $r->documentType->name,
                'step' => $r->documentType->step ?? 'checklist',
                'is_mandatory' => $r->is_mandatory,
                'instructions' => $r->instructions,
                'satisfied' => $application->documents->contains('document_type_id', $r->document_type_id),
            ])->values()->all(),
            'document_types' => DocumentType::where('is_active', true)->orderBy('name')
                ->get(['code', 'name', 'description', 'allowed_mimes', 'max_kb', 'step']),
            'options' => [
                'categories' => config('recruitment.categories'),
                'genders' => config('recruitment.genders'),
                'marital_statuses' => config('recruitment.marital_statuses'),
                'salutations' => config('recruitment.salutations'),
                'qualification_levels' => Qualification::LEVELS,
                'employment_types' => Employment::TYPES,
                'training_types' => Training::TYPES,
                'declaration_questions' => collect(Sections\DeclarationsSection::QUESTIONS)->map(fn ($q) => ['question' => $q[0], 'details_when' => $q[1]]),
                'posts' => $application->advertisement->posts()->where('status', 'open')->get(['id', 'code', 'name']),
            ],
            'privacy_notice' => $this->settings->get('privacy.notice'),
            'ready_to_submit' => collect($issues)->flatten()->isEmpty(),
        ];
    }

    public function documentSummary(ApplicationDocument $d): array
    {
        return [
            'uuid' => $d->uuid,
            'id' => $d->id,
            'document_type' => $d->documentType?->code,
            'document_type_name' => $d->documentType?->name,
            'label' => $d->label,
            'original_name' => $d->original_name,
            'mime' => $d->mime,
            'size' => $d->size,
            'documentable_type' => $d->documentable_type,
            'documentable_id' => $d->documentable_id,
            'verification_status' => $d->verification_status,
            'is_frozen' => $d->is_frozen,
            'uploaded_at' => $d->created_at?->toIso8601String(),
        ];
    }
}
