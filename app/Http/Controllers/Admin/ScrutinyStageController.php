<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Advertisement;
use App\Models\Role;
use App\Models\ScrutinyAssignment;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ScrutinyStageController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Advertisement $advertisement): JsonResponse
    {
        return response()->json($advertisement->scrutinyStages);
    }

    /** Replace the workflow. Stages already used by assignments are kept (and may only be renamed or reordered). */
    public function sync(Request $request, Advertisement $advertisement): JsonResponse
    {
        $data = $request->validate([
            'stages' => ['required', 'array', 'min:1', 'max:8'],
            'stages.*.id' => ['nullable', 'integer'],
            'stages.*.code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/', 'distinct'],
            'stages.*.name' => ['required', 'string', 'max:150'],
            'stages.*.role' => ['required', Rule::in([Role::SCRUTINY_USER, Role::RECRUITMENT_ADMIN, Role::SUPER_ADMIN])],
            'stages.*.is_decision_stage' => ['boolean'],
            'stages.*.allow_same_reviewer' => ['boolean'],
        ]);
        $stages = array_values($data['stages']);
        $decisionStages = collect($stages)->where('is_decision_stage', true);
        abort_unless($decisionStages->count() === 1 && end($stages)['is_decision_stage'], 422, 'Exactly one decision stage is required, and it must be the last stage.');

        DB::transaction(function () use ($advertisement, $stages) {
            $existing = $advertisement->scrutinyStages()->get()->keyBy('id');
            $used = ScrutinyAssignment::whereIn('scrutiny_stage_id', $existing->keys())->pluck('scrutiny_stage_id')->unique();
            $keep = collect($stages)->pluck('id')->filter();
            $removing = $existing->keys()->diff($keep);
            abort_if($removing->intersect($used)->isNotEmpty(), 422, 'A stage that already has assignments cannot be removed.');

            // Two passes so sequence/code uniqueness never collides mid-update.
            foreach ($existing as $stage) {
                $stage->update(['sequence' => $stage->sequence + 100, 'code' => $stage->code.'__'.$stage->id]);
            }
            $advertisement->scrutinyStages()->whereIn('id', $removing)->delete();
            foreach ($stages as $i => $s) {
                $values = ['sequence' => $i + 1, 'code' => $s['code'], 'name' => $s['name'], 'role' => $s['role'],
                    'is_decision_stage' => (bool) ($s['is_decision_stage'] ?? false), 'allow_same_reviewer' => (bool) ($s['allow_same_reviewer'] ?? false)];
                if (! empty($s['id']) && $existing->has($s['id'])) {
                    $existing[$s['id']]->update($values);
                } else {
                    $advertisement->scrutinyStages()->create($values);
                }
            }
            $this->audit->record('workflow.updated', $advertisement, [], ['stages' => $stages]);
        });

        return response()->json($advertisement->scrutinyStages()->get());
    }
}
