<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\RefereeInvitation;
use App\Models\RefereeResponse;
use App\Models\RefereeResponseAmendment;
use App\Services\AuditLogger;
use App\Services\DocumentService;
use App\Services\PdfService;
use App\Services\RefereeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RefereeController extends Controller
{
    public function __construct(
        private RefereeService $referees,
        private DocumentService $documents,
        private PdfService $pdf,
        private AuditLogger $audit,
    ) {}

    private function invitation(Application $application, string $uuid): RefereeInvitation
    {
        return RefereeInvitation::with('referee')->where('application_id', $application->id)->where('uuid', $uuid)->firstOrFail();
    }

    public function action(Request $request, Application $application, string $invitation, string $action): JsonResponse
    {
        $this->authorize('manageReferees', $application);
        $inv = $this->invitation($application, $invitation);
        $user = $request->user();

        match ($action) {
            'resend' => $this->referees->resend($inv, $user),
            'remind' => $this->referees->remind($inv, $user),
            'revoke' => $this->referees->revoke($inv, $user, $request->validate(['reason' => ['required', 'string', 'max:500']])['reason']),
            'replace' => (function () use ($request, $inv, $user) {
                $data = $request->validate(['reason' => ['required', 'string', 'max:500'], 'email' => ['nullable', 'email:rfc', 'max:255']]);
                $this->referees->replace($inv, $user, $data['reason'], $data['email'] ?? null);
            })(),
            'extend' => $this->referees->extend($inv, $user, new \DateTime($request->validate(['until' => ['required', 'date', 'after:today']])['until'])),
            default => abort(404),
        };

        return response()->json(['referees' => $this->referees->statusTable($application->fresh())]);
    }

    /** Invite a referee who has never been invited (e.g. after an earlier delivery problem was fixed). */
    public function invite(Request $request, Application $application, int $position): JsonResponse
    {
        $this->authorize('manageReferees', $application);
        abort_unless($application->submitted_at, 422, 'Invitations are sent after submission.');
        $referee = $application->referees()->where('position', $position)->firstOrFail();
        abort_if($referee->invitations()->whereIn('state', ['active', 'completed'])->exists(), 422, 'This referee already has a live invitation.');
        $this->referees->issue($referee, $request->user());

        return response()->json(['referees' => $this->referees->statusTable($application->fresh())]);
    }

    public function show(Request $request, Application $application, RefereeResponse $response): JsonResponse
    {
        $this->authorize('viewRecommendations', $application);
        abort_unless($response->application_id === $application->id, 404);
        $response->load(['referee:id,position', 'amendments.amender:id,name']);
        $this->documents->logAccess($request->user(), 'referee_response', $response->id, $application->id, 'view');
        $this->audit->record('referee.recommendation_viewed', $response, application: $application);

        return response()->json([
            ...$response->only(['id', 'reference_no', 'referee_name', 'referee_designation', 'referee_organization', 'referee_email',
                'referee_phone', 'relationship', 'known_duration', 'nature_of_association', 'candidate_entered', 'answers',
                'overall_recommendation', 'comments', 'content_hash']),
            'position' => $response->referee->position,
            'submitted_at' => $response->submitted_at->toIso8601String(),
            'amendments' => $response->amendments->map(fn ($a) => ['field' => $a->field, 'original' => $a->original_value, 'amended' => $a->amended_value, 'reason' => $a->reason, 'by' => $a->amender?->name, 'at' => $a->created_at?->toIso8601String()]),
        ]);
    }

    public function pdf(Request $request, Application $application, RefereeResponse $response)
    {
        $this->authorize('viewRecommendations', $application);
        abort_unless($response->application_id === $application->id, 404);
        $this->documents->logAccess($request->user(), 'referee_pdf', $response->id, $application->id, 'download');
        $this->audit->record('referee.recommendation_downloaded', $response, application: $application);
        $name = 'Referee-Recommendation-'.str_replace('/', '-', $response->reference_no).'.pdf';

        if ($response->amendments()->exists()) {
            return response($this->pdf->refereeRecommendationWithAmendments($response), 200, [
                'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$name.'"', 'Cache-Control' => 'private, no-store',
            ]);
        }
        if (! $response->pdf_path) {
            $response = $this->pdf->refereeRecommendation($response);
        }

        return $this->documents->stream($response->pdf_disk, $response->pdf_path, $name, 'application/pdf', $request->boolean('inline'));
    }

    /** A recorded correction alongside the immutable original. */
    public function amend(Request $request, Application $application, RefereeResponse $response): JsonResponse
    {
        $this->authorize('refereeExceptions', $application);
        $this->authorize('viewRecommendations', $application);
        abort_unless($response->application_id === $application->id, 404);
        $data = $request->validate([
            'field' => ['required', Rule::in(['referee_name', 'referee_designation', 'referee_organization', 'referee_email', 'referee_phone', 'relationship', 'known_duration', 'overall_recommendation', 'comments'])],
            'amended_value' => ['required', 'string', 'max:5000'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $amendment = RefereeResponseAmendment::create([
            'referee_response_id' => $response->id,
            'field' => $data['field'],
            'original_value' => (string) $response->{$data['field']},
            'amended_value' => $data['amended_value'],
            'reason' => $data['reason'],
            'amended_by' => $request->user()->id,
        ]);
        $this->audit->record('referee.recommendation_amended', $amendment, ['value' => $amendment->original_value], ['value' => $amendment->amended_value],
            ['field' => $data['field'], 'reason' => $data['reason']], application: $application);

        return response()->json(['ok' => true], 201);
    }
}
