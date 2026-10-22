<?php

namespace App\Http\Controllers\Applicant;

use App\Http\Controllers\Controller;
use App\Models\Advertisement;
use App\Models\Application;
use App\Models\Post;
use App\Services\ApplicationForm\ApplicationFormService;
use App\Services\ApplicationForm\SnapshotBuilder;
use App\Services\DocumentService;
use App\Services\PdfService;
use App\Services\RefereeService;
use App\Services\SubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApplicationController extends Controller
{
    public function __construct(
        private ApplicationFormService $form,
        private SubmissionService $submission,
        private SnapshotBuilder $snapshots,
        private PdfService $pdf,
        private DocumentService $documents,
        private RefereeService $referees,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $applications = Application::with(['advertisement:id,number,title,closes_at,status,opens_at', 'post:id,name,code', 'clarificationRequests' => fn ($q) => $q->whereIn('status', ['open', 'responded'])])
            ->where('user_id', $user->id)->latest()->get();

        $open = Advertisement::where('status', Advertisement::PUBLISHED)
            ->where('opens_at', '<=', now())->where('closes_at', '>=', now())
            ->with(['posts' => fn ($q) => $q->where('status', 'open')->select('id', 'advertisement_id', 'code', 'name', 'total_vacancies', 'pay_level')])
            ->orderBy('closes_at')->get(['id', 'number', 'title', 'closes_at', 'opens_at']);

        return response()->json([
            'email_verified' => $user->hasVerifiedEmail(),
            'applications' => $applications->map(fn (Application $a) => [
                'uuid' => $a->uuid,
                'reference_no' => $a->reference_no,
                'status' => $a->status,
                'post' => $a->post->name,
                'post_code' => $a->post->code,
                'advertisement' => $a->advertisement->number,
                'advertisement_title' => $a->advertisement->title,
                'closes_at' => $a->advertisement->closes_at->toIso8601String(),
                'submitted_at' => $a->submitted_at?->toIso8601String(),
                'updated_at' => $a->updated_at?->toIso8601String(),
                'editable' => $a->isEditableByApplicant(),
                'reopened_until' => $a->reopened_until?->toIso8601String(),
                'stage' => $this->publicStage($a),
                'fee_status' => $a->fee_status,
                'referee_status' => $a->referee_status,
                'open_clarifications' => $a->clarificationRequests->where('status', 'open')->count(),
                'version' => $a->version,
            ]),
            'open_advertisements' => $open->map(fn ($ad) => [
                'id' => $ad->id, 'number' => $ad->number, 'title' => $ad->title,
                'closes_at' => $ad->closes_at->toIso8601String(),
                'posts' => $ad->posts->map(fn ($p) => [...$p->only(['id', 'code', 'name', 'total_vacancies', 'pay_level']),
                    'applied' => $applications->contains('post_id', $p->id)]),
            ]),
            'notifications' => $user->unreadNotifications()->limit(10)->get(['id', 'data', 'created_at']),
        ]);
    }

    /** A plain-language stage for the candidate, without internal scrutiny detail. */
    private function publicStage(Application $a): string
    {
        return match (true) {
            $a->status === Application::DRAFT => 'Draft — not submitted',
            $a->status === Application::REOPENED => 'Reopened for correction',
            $a->status === Application::WITHDRAWN => 'Withdrawn',
            $a->scrutiny_status === 'clarification_required' => 'Clarification requested',
            $a->committee_status !== null => 'Under consideration by the Screening Committee',
            $a->scrutiny_status === 'decided' => 'Scrutiny completed',
            in_array($a->scrutiny_status, ['submitted', 'received'], true) => 'Submitted — received',
            default => 'Under scrutiny',
        };
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['post_id' => ['required', 'integer', 'exists:posts,id']]);
        $application = $this->form->create($request->user(), Post::findOrFail($data['post_id']));

        return response()->json(['uuid' => $application->uuid], 201);
    }

    public function show(Application $application): JsonResponse
    {
        $this->authorize('viewOwn', $application);

        return response()->json($this->form->payload($application));
    }

    public function saveSection(Request $request, Application $application, string $section): JsonResponse
    {
        $this->authorize('edit', $application);

        return response()->json($this->form->saveSection($application, $section, $request->except(['_token'])));
    }

    public function preview(Application $application): JsonResponse
    {
        $this->authorize('viewOwn', $application);

        return response()->json([
            'snapshot' => $this->snapshots->build($application),
            'issues' => $this->form->issues($application),
        ]);
    }

    public function previewPdf(Application $application)
    {
        $this->authorize('viewOwn', $application);

        return response($this->pdf->applicationPreview($this->snapshots->build($application)), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="application-draft-preview.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function submit(Request $request, Application $application): JsonResponse
    {
        $this->authorize('submit', $application);
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Confirm that you wish to submit the application.']);

        $application = $this->submission->submit($application, $request->user());

        return response()->json([
            'reference_no' => $application->reference_no,
            'submitted_at' => $application->submitted_at->toIso8601String(),
            'version' => $application->version,
        ]);
    }

    public function status(Application $application): JsonResponse
    {
        $this->authorize('viewOwn', $application);
        $application->load(['statusHistory' => fn ($q) => $q->whereIn('field', ['status', 'fee_status']), 'versions', 'clarificationRequests.responses', 'post', 'advertisement', 'payment', 'endorsement']);

        return response()->json([
            'uuid' => $application->uuid,
            'reference_no' => $application->reference_no,
            'status' => $application->status,
            'stage' => $this->publicStage($application),
            'post' => $application->post->name,
            'advertisement' => $application->advertisement->number,
            'submitted_at' => $application->submitted_at?->toIso8601String(),
            'fee_status' => $application->fee_status,
            'endorsement_status' => $application->endorsement_status,
            'fee_remarks' => $application->fee_status === 'rejected' ? $application->payment?->remarks : null,
            'endorsement_remarks' => $application->endorsement_status === 'rejected' ? $application->endorsement?->remarks : null,
            'can_withdraw' => $application->advertisement->allow_withdrawal && $application->status !== Application::WITHDRAWN
                && $application->outcome === null && $application->committee_status === null,
            'referees' => $application->submitted_at ? $this->referees->applicantStatus($application) : [],
            'versions' => $application->versions->map(fn ($v) => ['version' => $v->version, 'created_at' => $v->created_at?->toIso8601String(), 'reason' => $v->reason, 'has_pdf' => (bool) $v->pdf_path]),
            'history' => $application->statusHistory->map(fn ($h) => ['field' => $h->field, 'to' => $h->to_value, 'remarks' => $h->field === 'status' ? $h->remarks : null, 'at' => $h->created_at?->toIso8601String()]),
            'clarifications' => $application->clarificationRequests->map(fn ($c) => [
                'id' => $c->id, 'subject' => $c->subject, 'message' => $c->message, 'status' => $c->status,
                'due_at' => $c->due_at?->toIso8601String(), 'allow_uploads' => $c->allow_uploads,
                'can_respond' => $c->isOpenForResponse(), 'created_at' => $c->created_at?->toIso8601String(),
                'responses' => $c->responses->map(fn ($r) => ['message' => $r->message, 'at' => $r->created_at?->toIso8601String()]),
            ]),
        ]);
    }

    public function pdf(Request $request, Application $application, ?int $version = null)
    {
        $this->authorize('viewOwn', $application);
        $v = $version ? $application->versions()->where('version', $version)->firstOrFail() : $application->versions()->first();
        abort_unless($v, 404, 'The application has not been submitted.');
        if (! $v->pdf_path) {
            $v = $this->pdf->applicationVersion($v);
        }
        $this->documents->logAccess($request->user(), 'application_pdf', $v->id, $application->id, 'download');

        return $this->documents->stream($v->pdf_disk, $v->pdf_path, 'Application-'.str_replace('/', '-', $application->reference_no).'-v'.$v->version.'.pdf', 'application/pdf');
    }

    public function receipt(Request $request, Application $application)
    {
        $this->authorize('viewOwn', $application);
        abort_unless($application->submitted_at, 404, 'The application has not been submitted.');
        $this->documents->logAccess($request->user(), 'receipt', $application->id, $application->id, 'download');

        return response($this->pdf->acknowledgment($application), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="Acknowledgment-'.str_replace('/', '-', $application->reference_no).'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function withdraw(Request $request, Application $application): JsonResponse
    {
        $this->authorize('withdraw', $application);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500'], 'confirm' => ['accepted']]);
        $this->submission->withdraw($application, $request->user(), $data['reason'] ?? null);

        return response()->json(['status' => 'withdrawn']);
    }

    public function destroy(Application $application): JsonResponse
    {
        $this->authorize('viewOwn', $application);
        abort_unless($application->status === Application::DRAFT && ! $application->submitted_at, 422, 'Only an unsubmitted draft can be discarded.');
        $application->documents()->where('is_frozen', false)->get()->each(fn ($d) => $this->documents->delete($d));
        $application->delete();

        return response()->json(['deleted' => true]);
    }
}
