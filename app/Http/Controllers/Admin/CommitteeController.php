<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Committee;
use App\Models\CommitteeEvaluation;
use App\Services\CommitteeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The Screening Committee's read-only workspace and its own evaluation records. */
class CommitteeController extends Controller
{
    public function __construct(private CommitteeService $committees) {}

    public function queue(Request $request): JsonResponse
    {
        $user = $request->user();
        $committees = Committee::with(['advertisement:id,number,title,committee_evaluation_mode,evaluation_form_template_id', 'post:id,code,name'])
            ->when(! $user->isSuperAdmin(), fn ($q) => $q->whereHas('members', fn ($m) => $m->where('users.id', $user->id)))
            ->withCount('assignments')->get();

        $applications = Application::with(['personal:id,application_id,full_name,category', 'post:id,code,name', 'advertisement:id,number',
            'committeeEvaluations' => fn ($q) => $q->where('user_id', $user->id), 'committeeAssignments'])
            ->whereHas('committeeAssignments', fn ($q) => $q->whereIn('committee_id', $committees->pluck('id')))
            ->when($request->committee_id, fn ($q, $c) => $q->whereHas('committeeAssignments', fn ($x) => $x->where('committee_id', $c)))
            ->orderBy('reference_no')->paginate(50);

        return response()->json([
            'committees' => $committees->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'advertisement' => $c->advertisement->number,
                'post' => $c->post?->name, 'mode' => $c->effectiveMode(), 'count' => $c->assignments_count,
                'is_chair' => $this->committees->isChair($c->load('members'), $user)]),
            'data' => collect($applications->items())->map(fn (Application $a) => [
                'uuid' => $a->uuid, 'reference_no' => $a->reference_no, 'name' => $a->personal?->full_name,
                'category' => $a->personal?->category, 'post' => $a->post->name, 'advertisement' => $a->advertisement->number,
                'outcome' => $a->outcome, 'referee_status' => $a->referee_status, 'committee_status' => $a->committee_status,
                'my_evaluation' => $a->committeeEvaluations->first()?->status,
            ]),
            'meta' => ['total' => $applications->total(), 'current_page' => $applications->currentPage(), 'last_page' => $applications->lastPage()],
        ]);
    }

    public function context(Request $request, Application $application): JsonResponse
    {
        if ($request->user()->isSuperAdmin() && ! $application->committeeAssignments()->exists()) {
            abort(409, 'This application has not been forwarded to a Screening Committee yet.');
        }
        $this->authorize('evaluate', $application);
        $user = $request->user();
        $committee = $this->committees->committeeFor($application, $user);
        $isChair = $this->committees->isChair($committee, $user);
        $evaluations = CommitteeEvaluation::with('user:id,name')->where('committee_id', $committee->id)->where('application_id', $application->id)->get();

        return response()->json([
            'committee' => ['id' => $committee->id, 'name' => $committee->name, 'is_chair' => $isChair, 'members' => $committee->members->map->only(['id', 'name'])],
            'form' => $this->committees->formDefinition($committee),
            'my_evaluation' => $evaluations->firstWhere('user_id', $user->id)?->only(['responses', 'recommendation', 'reasons', 'status', 'submitted_at']),
            // Individual assessments are preserved and visible to the chair and to members once they submit their own.
            'evaluations' => ($isChair || $evaluations->firstWhere('user_id', $user->id)?->status === 'submitted' || $user->isSuperAdmin())
                ? $evaluations->where('status', 'submitted')->values()->map(fn ($e) => ['member' => $e->user?->name, 'responses' => $e->responses, 'recommendation' => $e->recommendation, 'reasons' => $e->reasons, 'submitted_at' => $e->submitted_at?->toIso8601String()])
                : [],
            'evaluation_summary' => $evaluations->where('status', 'submitted')->countBy('recommendation'),
            'decision' => $application->committeeDecisions()->where('committee_id', $committee->id)->with('recorder:id,name')->first(),
            'can_decide' => $isChair || $user->hasPermission('committee.record_decision'),
        ]);
    }

    public function evaluate(Request $request, Application $application): JsonResponse
    {
        $this->authorize('evaluate', $application);
        $evaluation = $this->committees->evaluate($application, $request->user(), $request->all(), $request->boolean('submit'));

        return response()->json($evaluation);
    }

    public function decide(Request $request, Application $application): JsonResponse
    {
        $this->authorize('evaluate', $application);
        $decision = $this->committees->decide($application, $request->user(), $request->all());

        return response()->json($decision);
    }

    public function comparison(Request $request, Committee $committee): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isSuperAdmin() || $committee->members()->where('users.id', $user->id)->exists()
            || ($user->hasPermission('reports.view') && $user->managedAdvertisements()->where('advertisements.id', $committee->advertisement_id)->exists()), 403);

        return response()->json(['committee' => $committee->only(['id', 'name']), 'rows' => $this->committees->comparison($committee, $user->hasPermission('referees.recommendations'))]);
    }
}
