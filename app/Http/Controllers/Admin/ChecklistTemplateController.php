<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChecklistAssignment;
use App\Models\ChecklistItem;
use App\Models\ChecklistTemplate;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ChecklistTemplateController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $templates = ChecklistTemplate::withCount('items')
            ->with(['assignments.advertisement:id,number', 'assignments.post:id,code'])
            ->when(! $request->boolean('include_archived'), fn ($q) => $q->whereNull('archived_at'))
            ->orderBy('name')->get();

        return response()->json(['templates' => $templates, 'sections' => ChecklistItem::SECTIONS]);
    }

    public function show(ChecklistTemplate $checklistTemplate): JsonResponse
    {
        $checklistTemplate->load(['items.documentType', 'assignments.advertisement:id,number,title', 'assignments.post:id,code,name']);

        return response()->json([...$checklistTemplate->toArray(), 'in_use' => $checklistTemplate->isInUse()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $template = DB::transaction(function () use ($data, $request) {
            $template = ChecklistTemplate::create(['name' => $data['name'], 'description' => $data['description'] ?? null, 'created_by' => $request->user()->id]);
            $this->syncItems($template, $data['items']);
            $this->audit->record('checklist.created', $template, [], ['name' => $template->name, 'items' => count($data['items'])]);

            return $template;
        });

        return response()->json($template->load('items'), 201);
    }

    public function update(Request $request, ChecklistTemplate $checklistTemplate): JsonResponse
    {
        abort_if($checklistTemplate->archived_at, 422, 'Archived checklists cannot be edited. Copy it instead.');
        $data = $this->validated($request, $checklistTemplate);
        DB::transaction(function () use ($checklistTemplate, $data) {
            $checklistTemplate->update(['name' => $data['name'], 'description' => $data['description'] ?? null]);
            $this->syncItems($checklistTemplate, $data['items']);
            $this->audit->record('checklist.updated', $checklistTemplate, [], ['name' => $checklistTemplate->name, 'items' => $data['items']]);
        });

        return response()->json($checklistTemplate->load('items'));
    }

    /**
     * Items removed from a template are soft-deleted, so verdicts and candidate
     * entries recorded against them keep their meaning.
     */
    private function syncItems(ChecklistTemplate $template, array $items): void
    {
        $existing = $template->items()->get()->keyBy('id');
        $keep = [];
        foreach (array_values($items) as $i => $item) {
            $values = collect($item)->only(['code', 'label', 'verification_instructions', 'section', 'document_type_id',
                'is_mandatory', 'requires_reason_if_not_verified', 'applicant_visible'])->all() + ['sort_order' => $i];
            if (! empty($item['id']) && $existing->has($item['id'])) {
                $existing[$item['id']]->update($values);
                $keep[] = $item['id'];
            } else {
                $keep[] = $template->items()->create($values)->id;
            }
        }
        $template->items()->whereNotIn('id', $keep)->get()->each->delete();
    }

    public function copy(Request $request, ChecklistTemplate $checklistTemplate): JsonResponse
    {
        $copy = DB::transaction(function () use ($checklistTemplate, $request) {
            $copy = ChecklistTemplate::create([
                'name' => $request->input('name', $checklistTemplate->name.' (copy)'),
                'description' => $checklistTemplate->description,
                'copied_from_id' => $checklistTemplate->id,
                'created_by' => $request->user()->id,
            ]);
            foreach ($checklistTemplate->items as $item) {
                $copy->items()->create($item->only(['code', 'label', 'verification_instructions', 'section', 'document_type_id',
                    'is_mandatory', 'requires_reason_if_not_verified', 'applicant_visible', 'sort_order']));
            }
            $this->audit->record('checklist.copied', $copy, [], ['from' => $checklistTemplate->name]);

            return $copy;
        });

        return response()->json($copy->load('items'), 201);
    }

    public function destroy(ChecklistTemplate $checklistTemplate): JsonResponse
    {
        if ($checklistTemplate->isInUse()) {
            $checklistTemplate->update(['archived_at' => now()]);
            $this->audit->record('checklist.archived', $checklistTemplate, [], [], ['reason' => 'In use; archived instead of deleted']);

            return response()->json(['archived' => true, 'message' => 'This checklist has been used in scrutiny, so it was archived rather than deleted.']);
        }
        $this->audit->record('checklist.deleted', $checklistTemplate, ['name' => $checklistTemplate->name]);
        $checklistTemplate->assignments()->delete();
        $checklistTemplate->delete();

        return response()->json(['deleted' => true]);
    }

    public function assign(Request $request, ChecklistTemplate $checklistTemplate): JsonResponse
    {
        abort_if($checklistTemplate->archived_at, 422, 'Archived checklists cannot be assigned.');
        $data = $request->validate([
            'advertisement_id' => ['required', 'exists:advertisements,id'],
            'post_ids' => ['nullable', 'array'],
            'post_ids.*' => ['integer', Rule::exists('posts', 'id')->where('advertisement_id', $request->input('advertisement_id'))],
        ]);
        foreach ($data['post_ids'] ?: [null] as $postId) {
            ChecklistAssignment::firstOrCreate(['checklist_template_id' => $checklistTemplate->id, 'advertisement_id' => $data['advertisement_id'], 'post_id' => $postId]);
        }
        $this->audit->record('checklist.assigned', $checklistTemplate, [], $data);

        return response()->json($checklistTemplate->load(['assignments.advertisement:id,number', 'assignments.post:id,code']));
    }

    public function unassign(ChecklistTemplate $checklistTemplate, ChecklistAssignment $assignment): JsonResponse
    {
        abort_unless($assignment->checklist_template_id === $checklistTemplate->id, 404);
        $this->audit->record('checklist.unassigned', $checklistTemplate, $assignment->toArray());
        $assignment->delete();

        return response()->json(['deleted' => true]);
    }

    private function validated(Request $request, ?ChecklistTemplate $template = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.code' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/', 'distinct'],
            'items.*.label' => ['required', 'string', 'max:500'],
            'items.*.verification_instructions' => ['nullable', 'string', 'max:2000'],
            'items.*.section' => ['required', Rule::in(ChecklistItem::SECTIONS)],
            'items.*.document_type_id' => ['nullable', 'exists:document_types,id'],
            'items.*.is_mandatory' => ['boolean'],
            'items.*.requires_reason_if_not_verified' => ['boolean'],
            'items.*.applicant_visible' => ['boolean'],
        ]);
    }
}
