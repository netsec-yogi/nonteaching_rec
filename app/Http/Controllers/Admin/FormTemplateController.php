<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EvaluationFormTemplate;
use App\Models\RefereeFormTemplate;
use App\Models\RefereeQuestion;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Referee recommendation forms and committee evaluation forms share a shape:
 * a template with ordered, typed questions. `{kind}` is `referee` or `evaluation`.
 */
class FormTemplateController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    private function model(string $kind): string
    {
        return match ($kind) {
            'referee' => RefereeFormTemplate::class,
            'evaluation' => EvaluationFormTemplate::class,
            default => abort(404),
        };
    }

    private function relation(string $kind): string
    {
        return $kind === 'referee' ? 'questions' : 'fields';
    }

    public function index(string $kind): JsonResponse
    {
        return response()->json($this->model($kind)::with($this->relation($kind))->orderBy('name')->get());
    }

    public function store(Request $request, string $kind): JsonResponse
    {
        $data = $this->validated($request, $kind);
        $template = DB::transaction(function () use ($kind, $data) {
            $template = $this->model($kind)::create($this->templateValues($kind, $data));
            $this->syncQuestions($template, $kind, $data['questions']);
            $this->audit->record("{$kind}_form.created", $template, [], ['name' => $template->name]);

            return $template;
        });

        return response()->json($template->load($this->relation($kind)), 201);
    }

    public function update(Request $request, string $kind, int $id): JsonResponse
    {
        $template = $this->model($kind)::findOrFail($id);
        $data = $this->validated($request, $kind);
        DB::transaction(function () use ($template, $kind, $data) {
            $template->update($this->templateValues($kind, $data));
            $this->syncQuestions($template, $kind, $data['questions']);
            $this->audit->record("{$kind}_form.updated", $template, [], ['name' => $template->name, 'questions' => $data['questions']]);
        });

        return response()->json($template->load($this->relation($kind)));
    }

    public function destroy(string $kind, int $id): JsonResponse
    {
        $template = $this->model($kind)::findOrFail($id);
        $this->audit->record("{$kind}_form.deleted", $template, ['name' => $template->name]);
        $template->delete(); // soft delete: submitted responses keep their question snapshot regardless

        return response()->json(['deleted' => true]);
    }

    private function templateValues(string $kind, array $data): array
    {
        return $kind === 'referee'
            ? collect($data)->only(['name', 'introduction', 'recommendation_options', 'good_faith_statement', 'is_active'])->all()
            : collect($data)->only(['name', 'description', 'recommendation_options', 'is_active'])->all();
    }

    private function syncQuestions(Model $template, string $kind, array $questions): void
    {
        $relation = $template->{$this->relation($kind)}();
        $existing = $relation->get()->keyBy('id');
        $keep = [];
        foreach (array_values($questions) as $i => $q) {
            $values = collect($q)->only(['code', 'label', 'help_text', 'type', 'options', 'is_required'])->all() + ['sort_order' => $i];
            if (! empty($q['id']) && $existing->has($q['id'])) {
                $existing[$q['id']]->update($values);
                $keep[] = $q['id'];
            } else {
                $keep[] = $relation->create($values)->id;
            }
        }
        $existing->except($keep)->each->delete();
    }

    private function validated(Request $request, string $kind): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'introduction' => ['nullable', 'string', 'max:5000'],
            'description' => ['nullable', 'string', 'max:5000'],
            'good_faith_statement' => [$kind === 'referee' ? 'required' : 'nullable', 'string', 'max:2000'],
            'recommendation_options' => ['required', 'array', 'min:2', 'max:10'],
            'recommendation_options.*' => ['required', 'string', 'max:100', 'distinct'],
            'is_active' => ['boolean'],
            'questions' => ['present', 'array', 'max:40'],
            'questions.*.id' => ['nullable', 'integer'],
            'questions.*.code' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/', 'distinct'],
            'questions.*.label' => ['required', 'string', 'max:500'],
            'questions.*.help_text' => ['nullable', 'string', 'max:500'],
            'questions.*.type' => ['required', Rule::in(RefereeQuestion::TYPES)],
            'questions.*.options' => ['nullable', 'array'],
            'questions.*.options.min' => ['nullable', 'integer', 'between:0,10'],
            'questions.*.options.max' => ['nullable', 'integer', 'between:1,10'],
            'questions.*.options.labels' => ['nullable', 'array'],
            'questions.*.options.choices' => ['nullable', 'array'],
            'questions.*.options.choices.*' => ['string', 'max:150'],
            'questions.*.is_required' => ['boolean'],
        ]);
    }
}
