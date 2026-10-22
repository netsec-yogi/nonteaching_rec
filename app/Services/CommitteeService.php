<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Committee;
use App\Models\CommitteeDecision;
use App\Models\CommitteeEvaluation;
use App\Models\EvaluationFormField;
use App\Models\RefereeResponse;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Screening Committee evaluations and decisions. Members only ever add their
 * own records; applications, documents and recommendations are read-only.
 */
class CommitteeService
{
    public const DECISIONS = ['recommended', 'not_recommended', 'further_review'];

    public function __construct(private StatusService $status, private AuditLogger $audit) {}

    public function committeeFor(Application $application, User $user): Committee
    {
        $committee = Committee::with(['members', 'advertisement.evaluationFormTemplate.fields', 'evaluationForm.fields'])
            ->whereHas('assignments', fn ($q) => $q->where('application_id', $application->id))
            ->whereHas('members', fn ($q) => $q->where('users.id', $user->id))
            ->first();

        if (! $committee && $user->isSuperAdmin()) {
            $committee = Committee::with(['members', 'advertisement.evaluationFormTemplate.fields', 'evaluationForm.fields'])
                ->whereHas('assignments', fn ($q) => $q->where('application_id', $application->id))->first();
        }

        return $committee ?? throw new AuthorizationException('This application is not assigned to your committee.');
    }

    public function isChair(Committee $committee, User $user): bool
    {
        return (bool) $committee->members->firstWhere('id', $user->id)?->pivot?->is_chair;
    }

    public function formDefinition(Committee $committee): array
    {
        $form = $committee->effectiveForm();

        return [
            'mode' => $committee->effectiveMode(),
            'fields' => $form ? $form->fields->map(fn (EvaluationFormField $f) => $f->only(['code', 'label', 'help_text', 'type', 'options', 'is_required']))->values()->all() : [],
            'recommendation_options' => $form?->recommendation_options ?? ['Recommended', 'Not Recommended', 'Requires Further Review'],
            'decisions' => self::DECISIONS,
        ];
    }

    public function evaluate(Application $application, User $user, array $input, bool $submit): CommitteeEvaluation
    {
        $committee = $this->committeeFor($application, $user);
        if ($committee->effectiveMode() !== 'individual') {
            throw ValidationException::withMessages(['mode' => 'This committee records a collective decision; individual evaluations are not used.']);
        }
        if (! $committee->members->contains('id', $user->id)) {
            throw new AuthorizationException('Only committee members record evaluations.');
        }

        $existing = CommitteeEvaluation::where(['committee_id' => $committee->id, 'application_id' => $application->id, 'user_id' => $user->id])->first();
        if ($existing?->status === 'submitted') {
            throw ValidationException::withMessages(['evaluation' => 'Your evaluation has been submitted and can no longer be changed.']);
        }

        $definition = $this->formDefinition($committee);
        $data = $this->validateResponses($definition, $input, $submit);

        return DB::transaction(function () use ($committee, $application, $user, $data, $submit, $existing) {
            $evaluation = CommitteeEvaluation::updateOrCreate(
                ['committee_id' => $committee->id, 'application_id' => $application->id, 'user_id' => $user->id],
                [
                    'responses' => $data['responses'] ?? [],
                    'recommendation' => $data['recommendation'] ?? '',
                    'reasons' => $data['reasons'] ?? null,
                    'status' => $submit ? 'submitted' : 'draft',
                    'submitted_at' => $submit ? now() : null,
                ],
            );
            $this->audit->record($submit ? 'committee.evaluation_submitted' : 'committee.evaluation_saved', $evaluation,
                $existing ? $existing->only(['responses', 'recommendation', 'reasons']) : [],
                $evaluation->only(['responses', 'recommendation', 'reasons']), application: $application, user: $user);

            return $evaluation;
        });
    }

    public function decide(Application $application, User $user, array $input): CommitteeDecision
    {
        $committee = $this->committeeFor($application, $user);
        $mayDecide = $this->isChair($committee, $user) || $user->hasPermission('committee.record_decision');
        if (! $mayDecide) {
            throw new AuthorizationException('Only the committee chair or an authorised officer records the committee decision.');
        }

        $definition = $this->formDefinition($committee);
        $validated = Validator::make($input, [
            'decision' => ['required', Rule::in(self::DECISIONS)],
            'reasons' => ['required', 'string', 'max:10000'],
            'responses' => ['nullable', 'array'],
        ])->validate();
        $responses = $definition['mode'] === 'collective'
            ? ($this->validateResponses($definition, $input, true)['responses'] ?? [])
            : null;

        return DB::transaction(function () use ($committee, $application, $user, $validated, $responses) {
            $existing = CommitteeDecision::where(['committee_id' => $committee->id, 'application_id' => $application->id])->first();
            $decision = CommitteeDecision::updateOrCreate(
                ['committee_id' => $committee->id, 'application_id' => $application->id],
                [
                    'decision' => $validated['decision'],
                    'reasons' => $validated['reasons'],
                    'responses' => $responses,
                    'recorded_by' => $user->id,
                    'decided_at' => now(),
                ],
            );
            $this->audit->record($existing ? 'committee.decision_revised' : 'committee.decision_recorded', $decision,
                $existing ? $existing->only(['decision', 'reasons']) : [], $decision->only(['decision', 'reasons']),
                application: $application, user: $user);
            $this->status->set($application, 'committee_status', $validated['decision'], $validated['reasons']);

            return $decision;
        });
    }

    private function validateResponses(array $definition, array $input, bool $strict): array
    {
        $rules = [
            'responses' => ['nullable', 'array'],
            'recommendation' => [$strict && $definition['mode'] === 'individual' ? 'required' : 'nullable', Rule::in($definition['recommendation_options'])],
            'reasons' => ['nullable', 'string', 'max:10000'],
        ];
        foreach ($definition['fields'] as $f) {
            $req = ($strict && $f['is_required']) ? 'required' : 'nullable';
            $rules["responses.{$f['code']}"] = [$req, ...match ($f['type']) {
                'yes_no' => [Rule::in(['yes', 'no'])],
                'rating' => ['integer', 'between:'.($f['options']['min'] ?? 1).','.($f['options']['max'] ?? 5)],
                'choice' => [Rule::in($f['options']['choices'] ?? [])],
                'short_text' => ['string', 'max:500'],
                default => ['string', 'max:5000'],
            }];
        }

        return Validator::make($input, $rules)->validate();
    }

    /** Side-by-side view of a committee's applications. */
    public function comparison(Committee $committee, bool $withRecommendations = false): array
    {
        $applications = Application::with(['personal', 'qualifications', 'post', 'refereeResponses:id,application_id,overall_recommendation',
            'committeeEvaluations' => fn ($q) => $q->where('committee_id', $committee->id)->where('status', 'submitted'),
            'committeeDecisions' => fn ($q) => $q->where('committee_id', $committee->id)])
            ->withSum('employments as experience_months', 'duration_months')
            ->whereHas('committeeAssignments', fn ($q) => $q->where('committee_id', $committee->id))
            ->orderBy('reference_no')
            ->get();

        $rank = array_flip(['class_10', 'class_12', 'diploma', 'graduation', 'post_graduation', 'doctorate']);

        return $applications->map(function (Application $a) use ($rank, $withRecommendations) {
            $highest = $a->qualifications->sortByDesc(fn ($q) => $rank[$q->level] ?? -1)->first();

            return [
                'uuid' => $a->uuid,
                'reference_no' => $a->reference_no,
                'name' => $a->personal?->full_name,
                'post' => $a->post->name,
                'category' => $a->personal?->category,
                'is_pwd' => (bool) $a->personal?->is_pwd,
                'highest_qualification' => $highest ? $highest->examination.($highest->percentage !== null ? ' ('.(float) $highest->percentage.'%)' : '') : null,
                'experience_months' => (int) $a->experience_months,
                'scrutiny_outcome' => $a->outcome,
                'referee_status' => $a->referee_status,
                'referee_recommendations' => $withRecommendations ? $a->refereeResponses->pluck('overall_recommendation')->values() : null,
                'evaluations' => $a->committeeEvaluations->countBy('recommendation'),
                'decision' => $a->committeeDecisions->first()?->decision,
            ];
        })->values()->all();
    }

    public function refereeResponsesFor(Application $application)
    {
        return RefereeResponse::with('referee:id,position')->where('application_id', $application->id)->orderBy('referee_id')->get();
    }
}
