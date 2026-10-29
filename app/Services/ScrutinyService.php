<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ChecklistItem;
use App\Models\ClarificationRequest;
use App\Models\ClarificationResponse;
use App\Models\Committee;
use App\Models\CommitteeAssignment;
use App\Models\DocumentRequirement;
use App\Models\DocumentType;
use App\Models\PostEligibilityCriterion;
use App\Models\Qualification;
use App\Models\ScrutinyAssignment;
use App\Models\ScrutinyItemResult;
use App\Models\ScrutinyObservation;
use App\Models\ScrutinyReview;
use App\Models\ScrutinyStage;
use App\Models\User;
use App\Notifications\ApplicantAlert;
use App\Notifications\StaffAlert;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Scrutiny of submitted applications. Nothing here writes to the candidate's
 * submitted data: verdicts, observations and clarifications are separate
 * records, and clarification documents are attached to the response.
 */
class ScrutinyService
{
    public function __construct(
        private StatusService $status,
        private RequirementResolver $requirements,
        private AuditLogger $audit,
        private TemplateMailer $mailer,
        private SettingsService $settings,
        private DocumentService $documents,
    ) {}

    // ---------------------------------------------------------- reception and assignment

    public function markReceived(Application $application, User $actor): void
    {
        $this->assertSubmitted($application);
        if ($application->scrutiny_status !== 'submitted') {
            return;
        }
        $this->status->set($application, 'scrutiny_status', 'received', 'Received by the Recruitment Section');

        $application->loadMissing(['user', 'personal', 'post', 'advertisement']);
        $this->mailer->send('application_acknowledgment', $application->user->email, $application->personal?->full_name, [
            'applicant_name' => $application->personal?->full_name,
            'reference_no' => $application->reference_no,
            'post_name' => $application->post->name,
            'advertisement_number' => $application->advertisement->number,
        ], $application, $application);
    }

    public function stages(Application $application): Collection
    {
        return ScrutinyStage::where('advertisement_id', $application->advertisement_id)->orderBy('sequence')->get();
    }

    public function assign(Application $application, User $assignee, ScrutinyStage $stage, User $actor): ScrutinyAssignment
    {
        $this->assertSubmitted($application);
        if ($stage->advertisement_id !== $application->advertisement_id) {
            throw ValidationException::withMessages(['stage' => 'This stage does not belong to the application’s advertisement.']);
        }
        if (! $assignee->is_active || ! $assignee->hasRole($stage->role)) {
            throw ValidationException::withMessages(['user_id' => "The selected user does not hold the role required at stage “{$stage->name}”."]);
        }
        if (! $stage->allow_same_reviewer && $this->reviewedEarlierStage($application, $assignee, $stage)) {
            throw ValidationException::withMessages(['user_id' => 'This user reported on an earlier stage of this application and cannot act at this stage.']);
        }

        return DB::transaction(function () use ($application, $assignee, $stage, $actor) {
            // One live assignment per stage: reassigning withdraws the previous one.
            ScrutinyAssignment::where('application_id', $application->id)->where('scrutiny_stage_id', $stage->id)
                ->whereIn('status', ['assigned', 'in_progress'])->where('user_id', '!=', $assignee->id)
                ->update(['status' => 'withdrawn']);

            $assignment = ScrutinyAssignment::firstOrCreate(
                ['application_id' => $application->id, 'scrutiny_stage_id' => $stage->id, 'user_id' => $assignee->id, 'status' => 'assigned'],
                ['assigned_by' => $actor->id],
            );

            if (! $application->current_stage_id) {
                $application->forceFill(['current_stage_id' => $stage->id])->save();
            }
            if (in_array($application->scrutiny_status, ['submitted', 'received'], true)) {
                $this->status->set($application, 'scrutiny_status', 'assigned');
            } elseif ($application->scrutiny_status === 'completed' && $application->current_stage_id === $stage->id) {
                $this->status->set($application, 'scrutiny_status', 'under_review');
            }

            $this->audit->record('scrutiny.assigned', $assignment, [], ['user' => $assignee->email, 'stage' => $stage->code], application: $application, user: $actor);

            $application->loadMissing(['personal', 'post']);
            $this->mailer->send('scrutiny_assigned', $assignee->email, $assignee->name, [
                'officer_name' => $assignee->name,
                'reference_no' => $application->reference_no,
                'applicant_name' => $application->personal?->full_name,
                'post_name' => $application->post->name,
                'stage_name' => $stage->name,
                'url' => rtrim(config('recruitment.frontend_url'), '/').'/scrutiny/'.$application->uuid,
            ], $assignment, $application);
            $assignee->notify(new StaffAlert('Application assigned', "{$application->reference_no} assigned to you for {$stage->name}.", '/scrutiny/'.$application->uuid));

            return $assignment;
        });
    }

    /** @param iterable<Application> $applications */
    public function bulkAssign(iterable $applications, User $assignee, ScrutinyStage $stage, User $actor): int
    {
        $count = 0;
        foreach ($applications as $application) {
            $this->assign($application, $assignee, $stage, $actor);
            $count++;
        }

        return $count;
    }

    public function activeAssignment(Application $application, User $user, ?ScrutinyStage $stage = null): ?ScrutinyAssignment
    {
        return ScrutinyAssignment::where('application_id', $application->id)
            ->where('user_id', $user->id)
            ->where('scrutiny_stage_id', $stage?->id ?? $application->current_stage_id)
            ->whereIn('status', ['assigned', 'in_progress'])
            ->first();
    }

    private function requireAssignment(Application $application, User $user): ScrutinyAssignment
    {
        $assignment = $this->activeAssignment($application, $user);
        if (! $assignment) {
            throw new AuthorizationException('You are not assigned to the current scrutiny stage of this application.');
        }

        return $assignment;
    }

    private function reviewedEarlierStage(Application $application, User $user, ScrutinyStage $stage): bool
    {
        return ScrutinyReview::where('application_id', $application->id)
            ->where('user_id', $user->id)
            ->where('status', 'submitted')
            ->whereHas('stage', fn ($q) => $q->where('sequence', '<', $stage->sequence))
            ->exists();
    }

    private function begin(Application $application, ScrutinyAssignment $assignment): void
    {
        if ($assignment->status === 'assigned') {
            $assignment->update(['status' => 'in_progress']);
        }
        if (in_array($application->scrutiny_status, ['submitted', 'received', 'assigned'], true)) {
            $this->status->set($application, 'scrutiny_status', 'in_progress');
        }
    }

    // ---------------------------------------------------------- checklist verdicts

    public function checklist(Application $application): array
    {
        $items = $this->requirements->checklistItems($application);
        $results = ScrutinyItemResult::with('user:id,name')->where('application_id', $application->id)->get()->keyBy('checklist_item_id');
        $entries = $application->checklistEntries()->get()->keyBy('checklist_item_id');

        return $items->map(function (ChecklistItem $item) use ($results, $entries) {
            $result = $results->get($item->id);
            $entry = $entries->get($item->id);

            return [
                'checklist_item_id' => $item->id,
                'code' => $item->code,
                'label' => $item->label,
                'section' => $item->section,
                'instructions' => $item->verification_instructions,
                'is_mandatory' => $item->is_mandatory,
                'requires_reason' => $item->requires_reason_if_not_verified,
                'document_type' => $item->documentType?->code,
                'candidate_status' => $entry?->candidate_status,
                'candidate_page_reference' => $entry?->page_reference,
                'candidate_document_id' => $entry?->document_id,
                'status' => $result->status ?? 'pending',
                'remarks' => $result?->remarks,
                'discrepancy_reason' => $result?->discrepancy_reason,
                'document_id' => $result?->document_id,
                'verified_by' => $result?->user?->name,
                'verified_at' => $result?->verified_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    public function recordItem(Application $application, ChecklistItem $item, array $data, User $user): ScrutinyItemResult
    {
        $assignment = $this->requireAssignment($application, $user);
        if (! $this->requirements->checklistItems($application)->contains('id', $item->id)) {
            throw ValidationException::withMessages(['checklist_item_id' => 'This item is not part of the checklist for this application.']);
        }
        if ($data['status'] === 'not_verified' && $item->requires_reason_if_not_verified && blank($data['discrepancy_reason'] ?? null)) {
            throw ValidationException::withMessages(['discrepancy_reason' => 'Give the reason for the discrepancy.']);
        }
        if (! empty($data['document_id'])
            && ! ApplicationDocument::where('application_id', $application->id)->whereKey($data['document_id'])->exists()) {
            throw ValidationException::withMessages(['document_id' => 'Choose a document from this application.']);
        }

        return DB::transaction(function () use ($application, $item, $data, $user, $assignment) {
            $this->begin($application, $assignment);
            $result = ScrutinyItemResult::firstOrNew(['application_id' => $application->id, 'checklist_item_id' => $item->id]);
            $old = $result->exists ? $result->only(['status', 'remarks', 'discrepancy_reason', 'document_id']) : [];
            $result->fill([
                'item_label' => $item->label,
                'status' => $data['status'],
                'remarks' => $data['remarks'] ?? null,
                'discrepancy_reason' => $data['status'] === 'not_verified' ? ($data['discrepancy_reason'] ?? null) : null,
                'document_id' => $data['document_id'] ?? null,
                'user_id' => $user->id,
                'verified_at' => $data['status'] === 'pending' ? null : now(),
            ])->save();

            $this->audit->record('scrutiny.item_recorded', $result, $old, $result->only(['status', 'remarks', 'discrepancy_reason', 'document_id']),
                ['item' => $item->code], application: $application, user: $user);

            return $result;
        });
    }

    /** Verify or reject a single uploaded document (recorded on the document, never altering the file). */
    public function verifyDocument(ApplicationDocument $document, string $status, ?string $remarks, User $user): void
    {
        $old = $document->only(['verification_status', 'verification_remarks']);
        $document->forceFill([
            'verification_status' => $status,
            'verification_remarks' => $remarks,
            'verified_by' => $user->id,
            'verified_at' => now(),
        ])->save();
        $this->audit->record('document.verification_recorded', $document, $old, $document->only(['verification_status', 'verification_remarks']), user: $user);
        $this->refreshDocumentStatus($document->application);
    }

    /**
     * The documents the advertisement's requirements ask of this application (conditions evaluated
     * against what the candidate declared), each with whether it is mandatory — the requirement's
     * override, else the document type's default — and what was uploaded and verified. A missing
     * optional document is reported as not provided, never as a deficiency.
     *
     * @return list<array<string, mixed>>
     */
    public function documentChecklist(Application $application): array
    {
        $documents = $application->documents()->get(['id', 'uuid', 'document_type_id', 'original_name', 'verification_status', 'verification_remarks',
            'documentable_type', 'documentable_id', 'upload_type', 'original_status', 'original_due_stage', 'original_received_at']);

        return $this->requirements->documentRequirements($application)->map(function (DocumentRequirement $r) use ($application, $documents) {
            $docs = $documents->where('document_type_id', $r->document_type_id);
            $accepts = $r->acceptsUndertaking();
            // An undertaking only counts where the requirement accepts one; otherwise the original is still missing.
            $usable = $accepts ? $docs : $docs->where('upload_type', '!=', ApplicationDocument::UNDERTAKING);
            $rows = $this->requirements->rowsWithoutDocument($r, $application, $documents);
            $missing = $rows === null ? $usable->isEmpty() : $rows->isNotEmpty();
            $mandatory = $r->isMandatory();
            $originalInHand = $docs->contains(fn ($d) => $d->upload_type !== ApplicationDocument::UNDERTAKING || $d->original_status === 'received_verified');
            $awaited = $usable->where('original_status', 'awaited');

            return [
                'document_type' => $r->documentType->code,
                'name' => $r->documentType->name,
                'mandatory' => $mandatory,
                'accepts_undertaking' => $accepts,
                'condition' => DocumentRequirement::CONDITIONS[$r->condition] ?? $r->condition,
                'source' => $r->isTypeDefault() ? 'document_type' : 'advertisement',
                'uploaded' => $docs->count(),
                'undertakings' => $docs->where('upload_type', ApplicationDocument::UNDERTAKING)->count(),
                'original_required' => $awaited->isNotEmpty(),
                'original_due_stage' => $awaited->first()?->original_due_stage,
                'missing_rows' => $rows?->count(),
                'status' => match (true) {
                    $missing => $mandatory ? 'missing' : 'not_provided',
                    $docs->contains('verification_status', 'rejected') || $docs->contains('original_status', 'received_rejected') => 'rejected',
                    ! $originalInHand && $awaited->isNotEmpty() => 'undertaking',
                    $usable->isNotEmpty() && $usable->every(fn ($d) => $d->verification_status === 'verified') && $awaited->isEmpty() => 'verified',
                    $usable->isEmpty() => 'not_applicable', // a per-row type with no rows to cover
                    default => 'pending',
                },
                'documents' => $docs->map(fn ($d) => ['id' => $d->id, 'uuid' => $d->uuid, 'name' => $d->original_name, 'upload_type' => $d->upload_type,
                    'verification_status' => $d->verification_status, 'remarks' => $d->verification_remarks,
                    'original_status' => $d->original_status, 'original_received_at' => $d->original_received_at?->toIso8601String()])->values()->all(),
            ];
        })->values()->all();
    }

    public function refreshDocumentStatus(Application $application): void
    {
        $docs = $application->documents()->get(['verification_status', 'upload_type', 'original_status']);
        $missingMandatory = collect($this->documentChecklist($application))->contains('status', 'missing');
        $status = match (true) {
            $docs->contains('verification_status', 'rejected'), $docs->contains('original_status', 'received_rejected'), $missingMandatory => 'deficient',
            // Everything accepted so far, but originals promised by undertakings are still to be produced.
            $docs->isNotEmpty() && $docs->every(fn ($d) => $d->verification_status === 'verified') && $docs->contains('original_status', 'awaited') => 'originals_awaited',
            $docs->isNotEmpty() && $docs->every(fn ($d) => $d->verification_status === 'verified') => 'verified',
            default => 'pending',
        };
        $this->status->set($application, 'document_status', $status);
    }

    // ---------------------------------------------------------- observations

    public function addObservation(Application $application, User $user, array $data): ScrutinyObservation
    {
        if (! $user->hasPermission('applications.assign')) {
            $this->requireAssignment($application, $user);
        }
        $observation = ScrutinyObservation::create([
            'application_id' => $application->id,
            'user_id' => $user->id,
            'scrutiny_stage_id' => $application->current_stage_id,
            'section' => $data['section'] ?? 'general',
            'observation' => $data['observation'],
            'is_deficiency' => (bool) ($data['is_deficiency'] ?? false),
        ]);
        $this->audit->record('scrutiny.observation_added', $observation, [], $observation->only(['section', 'observation', 'is_deficiency']), application: $application, user: $user);

        return $observation;
    }

    public function resolveObservation(ScrutinyObservation $observation, User $user): void
    {
        $observation->forceFill(['resolved_at' => now(), 'resolved_by' => $user->id])->save();
        $this->audit->record('scrutiny.observation_resolved', $observation, [], [], user: $user);
    }

    // ---------------------------------------------------------- clarifications

    public function requestClarification(Application $application, User $user, array $data): ClarificationRequest
    {
        $this->assertSubmitted($application);

        return DB::transaction(function () use ($application, $user, $data) {
            $request = ClarificationRequest::create([
                'application_id' => $application->id,
                'requested_by' => $user->id,
                'subject' => $data['subject'],
                'message' => $data['message'],
                'checklist_item_ids' => $data['checklist_item_ids'] ?? null,
                'allow_uploads' => (bool) ($data['allow_uploads'] ?? $application->advertisement->allow_clarification_uploads),
                'due_at' => isset($data['due_at']) ? Carbon::parse($data['due_at'])->endOfDay()
                    : now()->addDays((int) $this->settings->get('clarification.default_days'))->endOfDay(),
                'status' => 'open',
            ]);
            $this->status->set($application, 'scrutiny_status', 'clarification_required', $data['subject']);
            $this->audit->record('clarification.requested', $request, [], $request->only(['subject', 'due_at']), application: $application, user: $user);

            $application->loadMissing(['user', 'personal']);
            $this->mailer->send('clarification_requested', $application->user->email, $application->personal?->full_name, [
                'applicant_name' => $application->personal?->full_name,
                'reference_no' => $application->reference_no,
                'subject' => $request->subject,
                'message' => $request->message,
                'due_date' => $request->due_at->format('d M Y'),
                'url' => rtrim(config('recruitment.frontend_url'), '/').'/applications/'.$application->uuid,
            ], $request, $application);
            $application->user->notify(new ApplicantAlert('Clarification requested', $request->subject, '/applications/'.$application->uuid));

            return $request;
        });
    }

    /** @param list<array{file: UploadedFile, document_type: DocumentType, label: ?string}> $files */
    public function respondToClarification(ClarificationRequest $request, User $applicant, string $message, array $files): ClarificationResponse
    {
        if (! $request->isOpenForResponse()) {
            throw ValidationException::withMessages(['message' => 'This clarification request is closed or past its due date.']);
        }
        if ($files && ! $request->allow_uploads) {
            throw ValidationException::withMessages(['files' => 'Documents cannot be uploaded for this request.']);
        }

        return DB::transaction(function () use ($request, $applicant, $message, $files) {
            $application = $request->application;
            $response = ClarificationResponse::create([
                'clarification_request_id' => $request->id,
                'user_id' => $applicant->id,
                'message' => $message,
            ]);
            foreach ($files as $f) {
                $doc = $this->documents->store($application, $f['document_type'], $f['file'], $applicant, label: $f['label'] ?? 'Clarification: '.$request->subject, clarificationResponseId: $response->id);
                $doc->forceFill(['is_frozen' => true])->save();
            }
            $request->update(['status' => 'responded']);
            $this->status->set($application, 'scrutiny_status', 'clarification_received');
            $this->audit->record('clarification.responded', $response, [], ['files' => count($files)], application: $application, user: $applicant);

            $application->loadMissing('personal');
            $this->mailer->send('clarification_received', $applicant->email, $application->personal?->full_name, [
                'applicant_name' => $application->personal?->full_name,
                'reference_no' => $application->reference_no,
                'subject' => $request->subject,
            ], $response, $application);
            $request->requester?->notify(new StaffAlert('Clarification received', "{$application->reference_no}: {$request->subject}", '/scrutiny/'.$application->uuid));

            return $response;
        });
    }

    public function resolveClarification(ClarificationRequest $request, User $user, string $status, ?string $remarks): void
    {
        $request->update([
            'status' => $status, // resolved | closed
            'reviewed_by' => $user->id,
            'resolved_at' => now(),
            'resolution_remarks' => $remarks,
        ]);
        $application = $request->application;
        if (! $application->clarificationRequests()->whereIn('status', ['open', 'responded'])->exists()
            && in_array($application->scrutiny_status, ['clarification_required', 'clarification_received'], true)) {
            $this->status->set($application, 'scrutiny_status', 'in_progress', 'Clarification '.$status);
        }
        $this->audit->record('clarification.'.$status, $request, [], ['remarks' => $remarks], application: $application, user: $user);
    }

    // ---------------------------------------------------------- stage reports and decisions

    public function submitReview(Application $application, User $user, array $data): ScrutinyReview
    {
        $assignment = $this->requireAssignment($application, $user);
        $stage = $assignment->stage;

        if (! $stage->allow_same_reviewer && $this->reviewedEarlierStage($application, $user, $stage)) {
            throw new AuthorizationException('You reported on an earlier stage of this application and cannot approve it at this stage.');
        }
        if ($application->clarificationRequests()->where('status', 'open')->exists() && ! $stage->is_decision_stage
            && ($data['completeness'] ?? null) !== 'clarification_required') {
            throw ValidationException::withMessages(['completeness' => 'A clarification request is still open. Resolve it or report the application as requiring clarification.']);
        }

        if ($stage->is_decision_stage) {
            if (empty($data['decision'])) {
                throw ValidationException::withMessages(['decision' => 'Record the decision.']);
            }
            if ($data['decision'] !== 'recommended' && blank($data['reasons'] ?? null)) {
                throw ValidationException::withMessages(['reasons' => 'Record the reasons for this decision.']);
            }
        } else {
            $this->assertChecklistComplete($application);
            if (empty($data['completeness']) || empty($data['recommendation'])) {
                throw ValidationException::withMessages(['recommendation' => 'Record the completeness and your recommendation.']);
            }
            if (($data['recommendation'] === 'rejection' || $data['completeness'] !== 'complete') && blank($data['reasons'] ?? null)) {
                throw ValidationException::withMessages(['reasons' => 'Record the reasons.']);
            }
        }

        return DB::transaction(function () use ($application, $user, $data, $assignment, $stage) {
            $review = ScrutinyReview::updateOrCreate(
                ['application_id' => $application->id, 'scrutiny_stage_id' => $stage->id],
                [
                    'user_id' => $user->id,
                    'completeness' => $stage->is_decision_stage ? null : $data['completeness'],
                    'recommendation' => $stage->is_decision_stage ? null : $data['recommendation'],
                    'decision' => $stage->is_decision_stage ? $data['decision'] : null,
                    'reasons' => $data['reasons'] ?? null,
                    'summary' => $data['summary'] ?? null,
                    'status' => 'submitted',
                    'submitted_at' => now(),
                ],
            );
            $assignment->update(['status' => 'submitted', 'submitted_at' => now()]);
            $this->audit->record('scrutiny.report_submitted', $review, [], $review->only(['completeness', 'recommendation', 'decision', 'reasons']),
                ['stage' => $stage->code], application: $application, user: $user);

            if ($stage->is_decision_stage) {
                $this->applyDecision($application, $stage, $review, $user, (bool) ($data['notify_candidate'] ?? false));
            } else {
                $next = ScrutinyStage::where('advertisement_id', $application->advertisement_id)
                    ->where('sequence', '>', $stage->sequence)->orderBy('sequence')->first();
                if ($next) {
                    $application->forceFill(['current_stage_id' => $next->id])->save();
                }
                $this->status->set($application, 'scrutiny_status', 'completed', "{$stage->name} report submitted");
                $application->loadMissing('advertisement');
                foreach (app(RefereeService::class)->recruitmentStaff($application) as $admin) {
                    $this->mailer->send('scrutiny_completed', $admin->email, $admin->name, [
                        'admin_name' => $admin->name,
                        'reference_no' => $application->reference_no,
                        'stage_name' => $stage->name,
                        'officer_name' => $user->name,
                        'recommendation' => $data['recommendation'] === 'further_consideration' ? 'Recommended for further consideration' : 'Recommended for rejection',
                        'url' => rtrim(config('recruitment.frontend_url'), '/').'/admin/applications/'.$application->uuid,
                    ], $review, $application);
                }
            }

            return $review;
        });
    }

    private function applyDecision(Application $application, ScrutinyStage $stage, ScrutinyReview $review, User $user, bool $notify): void
    {
        if ($review->decision === 'returned') {
            $previous = ScrutinyStage::where('advertisement_id', $application->advertisement_id)
                ->where('sequence', '<', $stage->sequence)->orderByDesc('sequence')->first();
            if ($previous) {
                ScrutinyReview::where('application_id', $application->id)->where('scrutiny_stage_id', $previous->id)->update(['status' => 'draft']);
                ScrutinyAssignment::where('application_id', $application->id)->where('scrutiny_stage_id', $previous->id)
                    ->where('status', 'submitted')->update(['status' => 'in_progress', 'submitted_at' => null]);
                $application->forceFill(['current_stage_id' => $previous->id])->save();
            }
            // The decision-stage report is re-done after the earlier stage resubmits.
            $review->update(['status' => 'draft']);
            ScrutinyAssignment::where('application_id', $application->id)->where('scrutiny_stage_id', $stage->id)
                ->where('status', 'submitted')->update(['status' => 'assigned', 'submitted_at' => null]);
            $this->status->set($application, 'scrutiny_status', 'in_progress', 'Returned to '.($previous?->name ?? 'scrutiny').': '.$review->reasons);

            return;
        }

        $this->status->set($application, 'outcome', $review->decision, $review->reasons);
        $this->status->set($application, 'scrutiny_status', 'decided', "Decision recorded at {$stage->name}");

        if ($notify) {
            $application->loadMissing(['user', 'personal', 'post']);
            $this->mailer->send('decision_notification', $application->user->email, $application->personal?->full_name, [
                'applicant_name' => $application->personal?->full_name,
                'reference_no' => $application->reference_no,
                'post_name' => $application->post->name,
                'decision' => ['recommended' => 'found complete and has been recommended for further consideration', 'incomplete' => 'found incomplete', 'not_recommended' => 'not recommended for further consideration'][$review->decision],
                'reasons' => $review->reasons ?? '',
            ], $review, $application);
        }
    }

    private function assertChecklistComplete(Application $application): void
    {
        $results = ScrutinyItemResult::where('application_id', $application->id)->get()->keyBy('checklist_item_id');
        $pending = $this->requirements->checklistItems($application)
            ->filter(fn (ChecklistItem $i) => $i->is_mandatory && (($results[$i->id]->status ?? 'pending') === 'pending'));

        if ($pending->isNotEmpty()) {
            throw ValidationException::withMessages(['checklist' => 'Record a verdict on every mandatory checklist item: '.$pending->pluck('label')->take(5)->implode('; ').($pending->count() > 5 ? '…' : '')]);
        }
    }

    // ---------------------------------------------------------- committee hand-off

    /** @param iterable<Application> $applications */
    public function forwardToCommittee(iterable $applications, Committee $committee, User $actor, bool $override = false): int
    {
        $count = 0;
        $requireReferees = (bool) $this->settings->get('workflow.require_all_referees_before_committee');

        foreach ($applications as $application) {
            if ($application->advertisement_id !== $committee->advertisement_id
                || ($committee->post_id && $committee->post_id !== $application->post_id)) {
                throw ValidationException::withMessages(['committee_id' => "{$application->reference_no} is outside this committee’s scope."]);
            }
            if (! $override && $application->outcome !== 'recommended') {
                throw ValidationException::withMessages(['applications' => "{$application->reference_no} has not been recommended at scrutiny."]);
            }
            $application->loadMissing('advertisement');
            $refereesRequired = $requireReferees || $application->advertisement->referee_recommendations_mandatory;
            if (! $override && $refereesRequired && ! in_array($application->referee_status, ['complete', 'exception'], true)) {
                throw ValidationException::withMessages(['applications' => "{$application->reference_no} is awaiting referee recommendations."]);
            }

            DB::transaction(function () use ($application, $committee, $actor, $override) {
                CommitteeAssignment::firstOrCreate(['committee_id' => $committee->id, 'application_id' => $application->id], ['assigned_by' => $actor->id]);
                if (! $application->committee_status) {
                    $this->status->set($application, 'committee_status', 'pending', 'Forwarded to '.$committee->name);
                }
                $this->audit->record('committee.forwarded', $application, [], ['committee' => $committee->name], ['override' => $override], user: $actor);
            });
            $count++;
        }

        return $count;
    }

    // ---------------------------------------------------------- eligibility and report

    /** Advisory only: results guide reviewers and never change status. */
    public function eligibility(Application $application): array
    {
        $application->loadMissing(['qualifications', 'employments', 'personal', 'post.eligibilityCriteria', 'advertisement']);
        $p = $application->personal;
        $totalMonths = (int) $application->employments->sum('duration_months');

        return $application->post->eligibilityCriteria->map(function (PostEligibilityCriterion $c) use ($application, $totalMonths) {
            [$result, $basis] = match ($c->type) {
                'min_percentage' => $this->checkPercentage($application, $c),
                'min_experience_months' => [$totalMonths >= (int) $c->value ? 'met' : 'not_met',
                    sprintf('Declared experience %d yr %d mo; required %d months', intdiv($totalMonths, 12), $totalMonths % 12, (int) $c->value)],
                'max_age' => $this->checkAge($application, $c),
                'qualification_level' => $application->qualifications->contains('level', $c->qualification_level)
                    ? ['met', 'Declared: '.$application->qualifications->firstWhere('level', $c->qualification_level)->examination]
                    : ['not_met', 'No qualification declared at level '.(Qualification::LEVELS[$c->qualification_level] ?? $c->qualification_level)],
                default => ['manual', $c->description ?? 'Verify manually against the documents.'],
            };

            return ['label' => $c->label, 'type' => $c->type, 'is_essential' => $c->is_essential, 'result' => $result, 'basis' => $basis];
        })->values()->all();
    }

    private function checkPercentage(Application $application, PostEligibilityCriterion $c): array
    {
        $rows = $c->qualification_level
            ? $application->qualifications->where('level', $c->qualification_level)
            : $application->qualifications;
        if ($rows->isEmpty()) {
            return ['not_met', 'No qualification declared at the required level'];
        }
        $best = $rows->whereNotNull('percentage')->sortByDesc('percentage')->first();
        if (! $best) {
            return ['manual', 'Percentage not declared (grades / CGPA); verify from mark sheets'];
        }

        return [(float) $best->percentage >= (float) $c->value ? 'met' : 'not_met',
            sprintf('%s: %.2f%% declared; minimum %s%%', $best->examination, $best->percentage, $c->value)];
    }

    private function checkAge(Application $application, PostEligibilityCriterion $c): array
    {
        $dob = $application->personal?->date_of_birth;
        if (! $dob) {
            return ['manual', 'Date of birth not declared'];
        }
        $cutoff = $application->post->age_cutoff_date ?? $application->advertisement->closes_at;
        $age = $dob->diff($cutoff);
        $basis = sprintf('Age %d yr %d mo on %s; limit %s years', $age->y, $age->m, $cutoff->format('d-m-Y'), $c->value);
        if ($age->y < (int) $c->value || ($age->y === (int) $c->value && $age->m === 0 && $age->d === 0)) {
            return ['met', $basis];
        }
        $p = $application->personal;
        $relaxable = ($p->category && $p->category !== 'UR') || $p->is_pwd || $p->is_ex_serviceman || $application->is_in_service;

        return [$relaxable ? 'manual' : 'not_met', $basis.($relaxable ? ' — check applicable relaxation' : '')];
    }

    public function report(Application $application): array
    {
        $application->loadMissing(['personal', 'post', 'advertisement', 'observations.user', 'clarificationRequests', 'scrutinyReviews.stage', 'scrutinyReviews.user']);
        $checklist = $this->checklist($application);
        $label = fn ($s) => $s ? ucfirst(str_replace('_', ' ', $s)) : '—';

        $documents = $this->documentChecklist($application);
        $deficiencies = collect($checklist)->where('status', 'not_verified')
            ->map(fn ($i) => $i['label'].($i['discrepancy_reason'] ? ': '.$i['discrepancy_reason'] : ''))
            ->merge(collect($documents)->where('status', 'missing')->map(fn ($d) => 'Mandatory document not uploaded: '.$d['name'].($d['missing_rows'] ? " ({$d['missing_rows']} entr".($d['missing_rows'] === 1 ? 'y' : 'ies').' without one)' : '')))
            ->merge($application->observations->where('is_deficiency', true)->whereNull('resolved_at')->pluck('observation'))
            ->values()->all();

        return [
            'generated_at' => now()->toIso8601String(),
            'header' => [
                'candidate' => $application->personal?->full_name,
                'reference_no' => $application->reference_no,
                'advertisement_number' => $application->advertisement->number,
                'post' => $application->post->name,
                'category' => $application->personal?->category,
            ],
            'status' => [
                'fee' => $label($application->fee_status),
                'documents' => $label($application->document_status),
                'referees' => $label($application->referee_status),
                'endorsement' => $label($application->endorsement_status),
                'scrutiny' => $label($application->scrutiny_status),
                'outcome' => $application->outcome ? $label($application->outcome) : null,
            ],
            'items' => $checklist,
            'documents' => $documents,
            // Not deficiencies: undertakings accepted in place of documents whose originals are still to be produced.
            'originals_awaited' => collect($documents)->where('original_required', true)
                ->map(fn ($d) => $d['name'].' — signed undertaking submitted; original to be produced '.mb_strtolower($d['original_due_stage']))->values()->all(),
            'eligibility' => $this->eligibility($application),
            'observations' => $application->observations->map(fn ($o) => [
                'section' => $o->section, 'text' => $o->observation, 'deficiency' => $o->is_deficiency,
                'by' => $o->user?->name, 'at' => $o->created_at?->toIso8601String(), 'resolved_at' => $o->resolved_at?->toIso8601String(),
            ])->values()->all(),
            'deficiencies' => $deficiencies,
            'clarifications' => $application->clarificationRequests->map(fn ($c) => [
                'subject' => $c->subject, 'issued_at' => $c->created_at?->toIso8601String(), 'due_at' => $c->due_at?->toIso8601String(),
                'status' => $c->status, 'resolution' => $c->resolution_remarks,
            ])->values()->all(),
            'reviews' => $application->scrutinyReviews->where('status', 'submitted')->sortBy(fn ($r) => $r->stage->sequence)->map(fn ($r) => [
                'stage' => $r->stage->name, 'by' => $r->user?->name,
                'completeness' => $r->completeness ? $label($r->completeness) : null,
                'recommendation' => $r->recommendation ? $label($r->recommendation) : null,
                'decision' => $r->decision ? $label($r->decision) : null,
                'reasons' => $r->reasons, 'submitted_at' => $r->submitted_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    private function assertSubmitted(Application $application): void
    {
        if (! $application->submitted_at || $application->status === Application::WITHDRAWN) {
            throw ValidationException::withMessages(['application' => 'Only submitted applications can be scrutinised.']);
        }
    }
}
