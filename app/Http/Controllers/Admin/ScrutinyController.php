<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ChecklistItem;
use App\Models\ClarificationRequest;
use App\Models\ScrutinyAssignment;
use App\Models\ScrutinyItemResult;
use App\Models\ScrutinyObservation;
use App\Services\ScrutinyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The scrutiny officer's queue and workspace. */
class ScrutinyController extends Controller
{
    public function __construct(private ScrutinyService $scrutiny) {}

    public function queue(Request $request): JsonResponse
    {
        $assignments = ScrutinyAssignment::with(['application.personal:id,application_id,full_name,category', 'application.post:id,code,name',
            'application.advertisement:id,number', 'stage:id,name,is_decision_stage'])
            ->where('user_id', $request->user()->id)
            ->when($request->input('status', 'open') === 'open', fn ($q) => $q->whereIn('status', ['assigned', 'in_progress']))
            ->when($request->input('status') === 'submitted', fn ($q) => $q->where('status', 'submitted'))
            ->latest()->paginate(50);

        return response()->json([
            'data' => collect($assignments->items())->map(fn ($a) => [
                'assignment_id' => $a->id,
                'status' => $a->status,
                'stage' => $a->stage->name,
                'is_decision_stage' => $a->stage->is_decision_stage,
                'assigned_at' => $a->created_at?->toIso8601String(),
                'uuid' => $a->application->uuid,
                'reference_no' => $a->application->reference_no,
                'name' => $a->application->personal?->full_name,
                'category' => $a->application->personal?->category,
                'post' => $a->application->post->name,
                'advertisement' => $a->application->advertisement->number,
                'scrutiny_status' => $a->application->scrutiny_status,
                'fee_status' => $a->application->fee_status,
                'referee_status' => $a->application->referee_status,
            ]),
            'meta' => ['total' => $assignments->total(), 'current_page' => $assignments->currentPage(), 'last_page' => $assignments->lastPage()],
        ]);
    }

    /** Assignment context for the workspace; the application itself comes from the admin profile endpoint. */
    public function context(Request $request, Application $application): JsonResponse
    {
        $this->authorize('viewAsStaff', $application);
        $assignment = $this->scrutiny->activeAssignment($application, $request->user());
        $stage = $application->currentStage;

        return response()->json([
            'assignment' => $assignment ? ['id' => $assignment->id, 'status' => $assignment->status] : null,
            'stage' => $stage?->only(['id', 'name', 'code', 'is_decision_stage', 'allow_same_reviewer']),
            'can_act' => (bool) $assignment && $request->user()->can('scrutinize', $application),
            'can_correspond' => $request->user()->can('correspond', $application),
            'checklist' => $this->scrutiny->checklist($application),
            'documents' => $this->scrutiny->documentChecklist($application),
            'draft_review' => $application->scrutinyReviews()->where('scrutiny_stage_id', $stage?->id)->first()?->only(['completeness', 'recommendation', 'decision', 'reasons', 'summary', 'status']),
        ]);
    }

    public function recordItem(Request $request, Application $application, ChecklistItem $item): JsonResponse
    {
        $this->authorize('scrutinize', $application);
        $data = $request->validate([
            'status' => ['required', Rule::in(ScrutinyItemResult::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'discrepancy_reason' => ['nullable', 'string', 'max:2000'],
            'document_id' => ['nullable', 'integer'],
        ]);
        $this->scrutiny->recordItem($application, $item, $data, $request->user());

        return response()->json(['checklist' => $this->scrutiny->checklist($application->fresh())]);
    }

    public function addObservation(Request $request, Application $application): JsonResponse
    {
        $this->authorize('scrutinize', $application);
        $data = $request->validate([
            'section' => ['nullable', 'string', 'max:40'],
            'observation' => ['required', 'string', 'max:5000'],
            'is_deficiency' => ['boolean'],
        ]);
        $observation = $this->scrutiny->addObservation($application, $request->user(), $data);

        return response()->json($observation, 201);
    }

    public function resolveObservation(Request $request, Application $application, ScrutinyObservation $observation): JsonResponse
    {
        $this->authorize('scrutinize', $application);
        abort_unless($observation->application_id === $application->id, 404);
        $this->scrutiny->resolveObservation($observation, $request->user());

        return response()->json(['ok' => true]);
    }

    public function requestClarification(Request $request, Application $application): JsonResponse
    {
        $this->authorize('correspond', $application);
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:250'],
            'message' => ['required', 'string', 'max:5000'],
            'due_at' => ['nullable', 'date', 'after:today'],
            'allow_uploads' => ['boolean'],
            'checklist_item_ids' => ['nullable', 'array'],
            'checklist_item_ids.*' => ['integer', 'exists:checklist_items,id'],
        ]);
        $this->scrutiny->requestClarification($application, $request->user(), $data);

        return response()->json(['ok' => true], 201);
    }

    public function resolveClarification(Request $request, Application $application, ClarificationRequest $clarification): JsonResponse
    {
        $this->authorize('correspond', $application);
        abort_unless($clarification->application_id === $application->id, 404);
        $data = $request->validate(['status' => ['required', Rule::in(['resolved', 'closed'])], 'remarks' => ['nullable', 'string', 'max:2000']]);
        $this->scrutiny->resolveClarification($clarification, $request->user(), $data['status'], $data['remarks'] ?? null);

        return response()->json(['ok' => true]);
    }

    public function submitReview(Request $request, Application $application): JsonResponse
    {
        $this->authorize('scrutinize', $application);
        $data = $request->validate([
            'completeness' => ['nullable', Rule::in(['complete', 'incomplete', 'clarification_required'])],
            'recommendation' => ['nullable', Rule::in(['further_consideration', 'rejection'])],
            'decision' => ['nullable', Rule::in(['recommended', 'incomplete', 'not_recommended', 'returned'])],
            'reasons' => ['nullable', 'string', 'max:10000'],
            'summary' => ['nullable', 'string', 'max:10000'],
            'notify_candidate' => ['boolean'],
            'confirm' => ['accepted'],
        ]);
        $this->scrutiny->submitReview($application, $request->user(), $data);

        return response()->json(['scrutiny_status' => $application->fresh()->scrutiny_status, 'outcome' => $application->fresh()->outcome]);
    }

    public function report(Request $request, Application $application): JsonResponse
    {
        $this->authorize('viewAsStaff', $application);

        return response()->json($this->scrutiny->report($application));
    }
}
