<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\AuditLog;
use App\Models\Committee;
use App\Models\ScrutinyStage;
use App\Models\User;
use App\Services\ApplicationFilter;
use App\Services\ApplicationForm\ApplicationFormService;
use App\Services\ApplicationForm\SectionRegistry;
use App\Services\ApplicationForm\SnapshotBuilder;
use App\Services\AuditLogger;
use App\Services\DocumentService;
use App\Services\PdfService;
use App\Services\RefereeService;
use App\Services\ScrutinyService;
use App\Services\StatusService;
use App\Services\SubmissionService;
use App\Support\IdentityNumbers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ApplicationController extends Controller
{
    public function __construct(
        private ApplicationFilter $filter,
        private ApplicationFormService $form,
        private SnapshotBuilder $snapshots,
        private RefereeService $referees,
        private ScrutinyService $scrutiny,
        private SubmissionService $submission,
        private StatusService $status,
        private DocumentService $documents,
        private PdfService $pdf,
        private AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);
        $q = $this->filter->query($request->user(), $request->only(ApplicationFilter::FILTERS));
        $q = $this->filter->sorted($q, $request->sort, $request->direction)
            ->with(['personal:id,application_id,full_name,category,gender,email,mobile', 'post:id,code,name,department', 'advertisement:id,number', 'currentStage:id,name']);

        $page = $q->paginate(min((int) $request->input('per_page', 25), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn (Application $a) => [
                'uuid' => $a->uuid,
                'reference_no' => $a->reference_no,
                'name' => $a->personal?->full_name,
                'email' => $a->personal?->email,
                'category' => $a->personal?->category,
                'gender' => $a->personal?->gender,
                'advertisement' => $a->advertisement->number,
                'post' => $a->post->name,
                'post_code' => $a->post->code,
                'department' => $a->post->department,
                'status' => $a->status,
                'scrutiny_status' => $a->scrutiny_status,
                'current_stage' => $a->currentStage?->name,
                'outcome' => $a->outcome,
                'committee_status' => $a->committee_status,
                'fee_status' => $a->fee_status,
                'referee_status' => $a->referee_status,
                'endorsement_status' => $a->endorsement_status,
                'document_status' => $a->document_status,
                'submitted_at' => $a->submitted_at?->toIso8601String(),
                'updated_at' => $a->updated_at?->toIso8601String(),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, Application $application): JsonResponse
    {
        $this->authorize('viewAsStaff', $application);
        $user = $request->user();
        $application->load(['personal', 'post.advertisement', 'advertisement', 'currentStage', 'payment.verifications.verifier:id,name',
            'endorsement.verifier:id,name', 'documents.documentType', 'documents.verifier:id,name', 'documents.originalReceiver:id,name', 'versions.creator:id,name',
            'scrutinyAssignments.user:id,name', 'scrutinyAssignments.stage', 'scrutinyReviews.stage', 'scrutinyReviews.user:id,name',
            'observations.user:id,name', 'clarificationRequests.responses.documents.documentType', 'clarificationRequests.requester:id,name',
            'committeeAssignments.committee:id,name', 'committeeDecisions.recorder:id,name', 'committeeDecisions.committee:id,name',
            'reopenings.reopener:id,name', 'user:id,email,email_verified_at']);

        $latest = $application->versions->first();
        $can = [
            'verify' => $user->can('verify', $application),
            'assign' => $user->can('assign', $application),
            'reopen' => $user->can('reopen', $application),
            'correspond' => $user->can('correspond', $application),
            'download' => $user->can('downloadDocuments', $application),
            'referee_status' => $user->can('viewRefereeStatus', $application),
            'recommendations' => $user->can('viewRecommendations', $application),
            'manage_referees' => $user->can('manageReferees', $application),
            'referee_exceptions' => $user->can('refereeExceptions', $application),
            'history' => $user->can('viewHistory', $application),
            'audit' => $user->can('viewAudit', $application),
            'scrutinize' => $user->can('scrutinize', $application),
        ];

        return response()->json([
            'application' => [
                ...$application->only(['uuid', 'reference_no', 'status', 'scrutiny_status', 'outcome', 'committee_status', 'fee_status',
                    'endorsement_status', 'referee_status', 'document_status', 'version', 'is_in_service', 'referee_email_exception',
                    'referee_requirement_waived', 'referee_waiver_reason', 'withdrawal_reason']),
                'submitted_at' => $application->submitted_at?->toIso8601String(),
                'first_submitted_at' => $application->first_submitted_at?->toIso8601String(),
                'reopened_until' => $application->reopened_until?->toIso8601String(),
                'current_stage' => $application->currentStage?->only(['id', 'name', 'code', 'is_decision_stage']),
                'advertisement' => $application->advertisement->only(['id', 'number', 'title', 'referees_required', 'referee_recommendations_mandatory']),
                'post' => $application->post->only(['id', 'code', 'name', 'department']),
                'candidate_email_verified' => $application->user->hasVerifiedEmail(),
            ],
            // Reviewers read the frozen submission; a draft reopening is shown separately.
            'snapshot' => $latest?->snapshot ?? $this->snapshots->build($application),
            'live_differs' => $application->status === Application::REOPENED,
            'versions' => $application->versions->map(fn ($v) => ['version' => $v->version, 'reason' => $v->reason, 'created_at' => $v->created_at?->toIso8601String(), 'by' => $v->creator?->name, 'has_pdf' => (bool) $v->pdf_path, 'pdf_sha256' => $v->pdf_sha256]),
            'documents' => $application->documents->map(fn (ApplicationDocument $d) => [
                ...$this->form->documentSummary($d),
                'verification_remarks' => $d->verification_remarks,
                'verified_by' => $d->verifier?->name,
                'verified_at' => $d->verified_at?->toIso8601String(),
                'clarification_response_id' => $d->clarification_response_id,
                'original_due_stage' => $d->original_due_stage,
                'original_received_at' => $d->original_received_at?->toIso8601String(),
                'original_received_by' => $d->originalReceiver?->name,
                'original_remarks' => $d->original_remarks,
            ]),
            'identity' => [
                'pan' => IdentityNumbers::maskPan($application->personal?->pan_number),
                'aadhaar' => IdentityNumbers::maskAadhaar($application->personal?->aadhaar_number),
                'can_reveal' => $user->hasPermission('identity.view'),
            ],
            'payment' => $application->payment ? [
                ...$application->payment->only(['method', 'utr_number', 'bank_name', 'status', 'remarks', 'exemption_basis']),
                'amount' => (float) $application->payment->amount,
                'amount_due' => (float) $application->payment->amount_due,
                'transaction_date' => $application->payment->transaction_date?->toDateString(),
                'verified_at' => $application->payment->verified_at?->toIso8601String(),
                'config_version' => $application->payment->fee_payment_config_version,
                'paid_to' => ($snap = $application->payment->payment_config_snapshot) ? [
                    'account_holder' => $snap['account_holder'] ?? null, 'bank_name' => $snap['bank_name'] ?? null, 'ifsc' => $snap['ifsc'] ?? null,
                    'account_number_last4' => isset($snap['account_number']) ? substr((string) $snap['account_number'], -4) : null, 'upi_id' => $snap['upi_id'] ?? null,
                ] : null,
                'history' => $application->payment->verifications->map(fn ($v) => ['status' => $v->status, 'remarks' => $v->remarks, 'by' => $v->verifier?->name, 'at' => $v->created_at?->toIso8601String()]),
            ] : null,
            'endorsement' => $application->endorsement ? [
                'verification_status' => $application->endorsement->verification_status,
                'remarks' => $application->endorsement->remarks,
                'verified_by' => $application->endorsement->verifier?->name,
                'verified_at' => $application->endorsement->verified_at?->toIso8601String(),
            ] : null,
            'referees' => $can['referee_status'] ? $this->referees->statusTable($application) : null,
            'recommendations' => $can['recommendations'] ? $application->refereeResponses()->with('referee:id,position')->get()
                ->map(fn ($r) => ['id' => $r->id, 'position' => $r->referee->position, 'reference_no' => $r->reference_no, 'referee_name' => $r->referee_name,
                    'overall_recommendation' => $r->overall_recommendation, 'submitted_at' => $r->submitted_at?->toIso8601String()]) : null,
            'scrutiny' => [
                'stages' => $this->scrutiny->stages($application)->map->only(['id', 'sequence', 'code', 'name', 'role', 'is_decision_stage', 'allow_same_reviewer']),
                'assignments' => $application->scrutinyAssignments->map(fn ($a) => ['id' => $a->id, 'user' => $a->user?->name, 'user_id' => $a->user_id, 'stage' => $a->stage?->name, 'stage_id' => $a->scrutiny_stage_id, 'status' => $a->status, 'assigned_at' => $a->created_at?->toIso8601String(), 'submitted_at' => $a->submitted_at?->toIso8601String()]),
                'reviews' => $application->scrutinyReviews->where('status', 'submitted')->values()->map(fn ($r) => ['stage' => $r->stage->name, 'by' => $r->user?->name, 'completeness' => $r->completeness, 'recommendation' => $r->recommendation, 'decision' => $r->decision, 'reasons' => $r->reasons, 'summary' => $r->summary, 'submitted_at' => $r->submitted_at?->toIso8601String()]),
                'checklist' => $this->scrutiny->checklist($application),
                'observations' => $application->observations->map(fn ($o) => ['id' => $o->id, 'section' => $o->section, 'observation' => $o->observation, 'is_deficiency' => $o->is_deficiency, 'by' => $o->user?->name, 'at' => $o->created_at?->toIso8601String(), 'resolved_at' => $o->resolved_at?->toIso8601String()]),
                'eligibility' => $this->scrutiny->eligibility($application),
            ],
            'clarifications' => $application->clarificationRequests->map(fn ($c) => [
                'id' => $c->id, 'subject' => $c->subject, 'message' => $c->message, 'status' => $c->status, 'by' => $c->requester?->name,
                'due_at' => $c->due_at?->toIso8601String(), 'created_at' => $c->created_at?->toIso8601String(), 'resolution_remarks' => $c->resolution_remarks,
                'responses' => $c->responses->map(fn ($r) => ['message' => $r->message, 'at' => $r->created_at?->toIso8601String(),
                    'documents' => $r->documents->map(fn ($d) => $this->form->documentSummary($d))]),
            ]),
            'committee' => [
                'assignments' => $application->committeeAssignments->map(fn ($a) => ['committee' => $a->committee?->name, 'at' => $a->created_at?->toIso8601String()]),
                'decisions' => $application->committeeDecisions->map(fn ($d) => ['committee' => $d->committee?->name, 'decision' => $d->decision, 'reasons' => $d->reasons, 'by' => $d->recorder?->name, 'at' => $d->decided_at?->toIso8601String()]),
            ],
            'reopenings' => $application->reopenings->map(fn ($r) => ['reason' => $r->reason, 'by' => $r->reopener?->name, 'sections' => $r->sections, 'from_version' => $r->from_version, 'until' => $r->reopened_until?->toIso8601String(), 'resubmitted_at' => $r->resubmitted_at?->toIso8601String(), 'at' => $r->created_at?->toIso8601String()]),
            'section_keys' => collect(app(SectionRegistry::class)->all())->map(fn ($s, $k) => ['key' => $k, 'title' => $s->title(), 'form_reference' => $s->formReference()])->values(),
            'can' => $can,
        ]);
    }

    public function history(Request $request, Application $application): JsonResponse
    {
        $this->authorize('viewHistory', $application);

        return response()->json($application->statusHistory()->with('user:id,name')->get()
            ->map(fn ($h) => ['field' => $h->field, 'from' => $h->from_value, 'to' => $h->to_value, 'remarks' => $h->remarks, 'by' => $h->user?->name, 'at' => $h->created_at?->toIso8601String()]));
    }

    public function audit(Request $request, Application $application): JsonResponse
    {
        $this->authorize('viewAudit', $application);

        return response()->json(AuditLog::with('user:id,name')->where('application_id', $application->id)->orderByDesc('id')->limit(500)->get());
    }

    public function versionPdf(Request $request, Application $application, int $version)
    {
        $this->authorize('viewAsStaff', $application);
        $v = $application->versions()->where('version', $version)->firstOrFail();
        if (! $v->pdf_path) {
            $v = $this->pdf->applicationVersion($v);
        }
        $this->documents->logAccess($request->user(), 'application_pdf', $v->id, $application->id, $request->boolean('inline') ? 'view' : 'download');

        return $this->documents->stream($v->pdf_disk, $v->pdf_path, 'Application-'.str_replace('/', '-', $application->reference_no).'-v'.$v->version.'.pdf', 'application/pdf', $request->boolean('inline'));
    }

    public function markReceived(Request $request, Application $application): JsonResponse
    {
        $this->authorize('assign', $application);
        $this->scrutiny->markReceived($application, $request->user());

        return response()->json(['scrutiny_status' => $application->fresh()->scrutiny_status]);
    }

    public function verifyPayment(Request $request, Application $application): JsonResponse
    {
        $this->authorize('verify', $application);
        $data = $request->validate(['status' => ['required', Rule::in(['verified', 'rejected', 'pending_verification'])], 'remarks' => ['required_if:status,rejected', 'nullable', 'string', 'max:500']],
            ['remarks.required_if' => 'Give the reason for rejecting the payment.']);
        $payment = $application->payment;
        abort_unless($payment && $payment->status !== 'exempt', 422, 'There is no payment to verify.');
        abort_unless($application->submitted_at, 422, 'The fee can be verified after the application is submitted.');

        DB::transaction(function () use ($payment, $data, $request, $application) {
            $old = $payment->only(['status', 'remarks']);
            $payment->forceFill(['status' => $data['status'], 'remarks' => $data['remarks'] ?? null, 'verified_by' => $request->user()->id, 'verified_at' => now()])->save();
            $this->audit->record('payment.verification_recorded', $payment, $old, $payment->only(['status', 'remarks']), application: $application);
            $payment->verifications()->create(['status' => $data['status'], 'remarks' => $data['remarks'] ?? null, 'verified_by' => $request->user()->id]);
            $this->status->set($application, 'fee_status', $data['status'], $data['remarks'] ?? null);
        });

        return response()->json(['fee_status' => $application->fresh()->fee_status]);
    }

    /**
     * Record that the original of a document submitted as a signed undertaking has been produced
     * (and checked) — at the interview / document verification. The undertaking, its scrutiny
     * decision and the history stay; the audit trail records who recorded it and when.
     */
    public function recordOriginal(Request $request, Application $application, ApplicationDocument $document): JsonResponse
    {
        $this->authorize('verify', $application);
        abort_unless($document->application_id === $application->id, 404);
        abort_unless($document->isUndertaking(), 422, 'This document was uploaded as the original; there is no undertaking to settle.');
        $data = $request->validate([
            'status' => ['required', Rule::in(['received_verified', 'received_rejected', 'awaited'])],
            'remarks' => ['required_if:status,received_rejected', 'nullable', 'string', 'max:500'],
        ], ['remarks.required_if' => 'Give the reason the original was not accepted.']);

        DB::transaction(function () use ($document, $data, $request, $application) {
            $old = $document->only(['original_status', 'original_remarks', 'original_received_at']);
            $received = $data['status'] !== 'awaited';
            $document->forceFill([
                'original_status' => $data['status'],
                'original_remarks' => $data['remarks'] ?? null,
                'original_received_at' => $received ? now() : null,
                'original_received_by' => $received ? $request->user()->id : null,
            ])->save();
            $this->audit->record('document.original_recorded', $document, $old, $document->only(['original_status', 'original_remarks', 'original_received_at']), application: $application);
            $this->scrutiny->refreshDocumentStatus($application);
        });

        return response()->json(['ok' => true, 'document_status' => $application->fresh()->document_status]);
    }

    /** Full PAN / Aadhaar numbers, for users given that permission; every view is logged. */
    public function identity(Request $request, Application $application): JsonResponse
    {
        $this->authorize('viewAsStaff', $application);
        abort_unless($request->user()->hasPermission('identity.view'), 403, 'You are not authorised to view full identity numbers.');
        $p = $application->personal;
        $this->documents->logAccess($request->user(), 'identity_numbers', $application->id, $application->id, 'view');
        $this->audit->record('application.identity_numbers_viewed', $application, [], [], ['fields' => array_keys(array_filter(['pan' => $p?->pan_number, 'aadhaar' => $p?->aadhaar_number]))]);

        return response()->json(['pan' => $p?->pan_number, 'aadhaar' => $p?->aadhaar_number])
            ->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function verifyEndorsement(Request $request, Application $application): JsonResponse
    {
        $this->authorize('verify', $application);
        $data = $request->validate(['status' => ['required', Rule::in(['verified', 'rejected', 'pending'])], 'remarks' => ['required_if:status,rejected', 'nullable', 'string', 'max:500']]);
        $endorsement = $application->endorsement;
        abort_unless($endorsement, 422, 'No employer endorsement has been submitted.');

        DB::transaction(function () use ($endorsement, $data, $request, $application) {
            $old = $endorsement->only(['verification_status', 'remarks']);
            $endorsement->forceFill(['verification_status' => $data['status'], 'remarks' => $data['remarks'] ?? null, 'verified_by' => $request->user()->id, 'verified_at' => now()])->save();
            $this->audit->record('endorsement.verification_recorded', $endorsement, $old, $endorsement->only(['verification_status', 'remarks']), application: $application);
            $this->status->set($application, 'endorsement_status', $data['status'] === 'pending' ? 'submitted' : $data['status'], $data['remarks'] ?? null);
        });

        return response()->json(['endorsement_status' => $application->fresh()->endorsement_status]);
    }

    public function verifyDocument(Request $request, Application $application, ApplicationDocument $document): JsonResponse
    {
        $this->authorize('verify', $application);
        abort_unless($document->application_id === $application->id, 404);
        $data = $request->validate(['status' => ['required', Rule::in(['verified', 'rejected', 'pending'])], 'remarks' => ['required_if:status,rejected', 'nullable', 'string', 'max:500']]);
        $this->scrutiny->verifyDocument($document, $data['status'], $data['remarks'] ?? null, $request->user());

        return response()->json(['ok' => true]);
    }

    public function assign(Request $request, Application $application): JsonResponse
    {
        $this->authorize('assign', $application);
        $data = $request->validate(['user_id' => ['required', 'exists:users,id'], 'stage_id' => ['required', 'exists:scrutiny_stages,id']]);
        $this->scrutiny->assign($application, User::findOrFail($data['user_id']), ScrutinyStage::findOrFail($data['stage_id']), $request->user());

        return response()->json(['ok' => true]);
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'application_uuids' => ['required', 'array', 'min:1', 'max:500'],
            'application_uuids.*' => ['uuid'],
            'user_id' => ['required', 'exists:users,id'],
            'stage_id' => ['required', 'exists:scrutiny_stages,id'],
        ]);
        $applications = Application::whereIn('uuid', $data['application_uuids'])->get();
        foreach ($applications as $a) {
            $this->authorize('assign', $a);
        }
        $stage = ScrutinyStage::findOrFail($data['stage_id']);
        $count = DB::transaction(fn () => $this->scrutiny->bulkAssign($applications, User::findOrFail($data['user_id']), $stage, $request->user()));

        return response()->json(['assigned' => $count]);
    }

    public function forward(Request $request): JsonResponse
    {
        $data = $request->validate([
            'application_uuids' => ['required', 'array', 'min:1', 'max:500'],
            'application_uuids.*' => ['uuid'],
            'committee_id' => ['required', 'exists:committees,id'],
            'override' => ['boolean'],
            'override_reason' => ['required_if:override,true', 'nullable', 'string', 'max:500'],
        ]);
        $applications = Application::with('advertisement')->whereIn('uuid', $data['application_uuids'])->get();
        foreach ($applications as $a) {
            $this->authorize('assign', $a);
        }
        $override = (bool) ($data['override'] ?? false);
        abort_if($override && ! $request->user()->isSuperAdmin(), 403, 'Only the Super Administrator can forward without the normal prerequisites.');
        $count = DB::transaction(fn () => $this->scrutiny->forwardToCommittee($applications, Committee::findOrFail($data['committee_id']), $request->user(), $override));

        return response()->json(['forwarded' => $count]);
    }

    public function reopen(Request $request, Application $application): JsonResponse
    {
        $this->authorize('reopen', $application);
        $keys = array_keys(app(SectionRegistry::class)->all());
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'until' => ['nullable', 'date', 'after:now'],
            'sections' => ['nullable', 'array'],
            'sections.*' => [Rule::in($keys)],
        ]);
        $this->submission->reopen($application, $request->user(), $data['reason'], isset($data['until']) ? new \DateTime($data['until']) : null, $data['sections'] ?? null);

        return response()->json(['status' => $application->fresh()->status]);
    }

    public function refereeEmailException(Request $request, Application $application): JsonResponse
    {
        $this->authorize('refereeExceptions', $application);
        $data = $request->validate(['allow' => ['required', 'boolean'], 'note' => ['required_if:allow,true', 'nullable', 'string', 'max:500']]);
        $application->forceFill(['referee_email_exception' => $data['allow'], 'referee_exception_note' => $data['note'] ?? null])->save();
        $this->audit->record('referee.email_exception', $application, [], ['allow' => $data['allow']], ['note' => $data['note'] ?? null]);

        return response()->json(['ok' => true]);
    }

    public function refereeWaiver(Request $request, Application $application): JsonResponse
    {
        $this->authorize('refereeExceptions', $application);
        $data = $request->validate(['waive' => ['required', 'boolean'], 'reason' => ['required_if:waive,true', 'nullable', 'string', 'max:500']]);
        $this->referees->waiveRequirement($application, $request->user(), $data['waive'], $data['reason'] ?? null);

        return response()->json(['referee_status' => $application->fresh()->referee_status]);
    }

    public function scrutinyReportPdf(Request $request, Application $application)
    {
        $this->authorize('viewAsStaff', $application);
        $this->documents->logAccess($request->user(), 'scrutiny_report', $application->id, $application->id, 'download');

        return response($this->pdf->scrutinyReport($this->scrutiny->report($application)), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="Scrutiny-Report-'.str_replace('/', '-', $application->reference_no).'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
