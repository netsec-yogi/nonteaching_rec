<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RecruitmentCycle;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CycleController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return response()->json(RecruitmentCycle::withCount('advertisements')->orderByDesc('year')->get());
    }

    /** Names only, for filter pickers available to every staff role. */
    public function options(): JsonResponse
    {
        return response()->json(RecruitmentCycle::orderByDesc('year')->get(['id', 'name', 'year']));
    }

    public function store(Request $request): JsonResponse
    {
        $cycle = RecruitmentCycle::create($this->validated($request));
        $this->audit->record('cycle.created', $cycle, [], $cycle->toArray());

        return response()->json($cycle, 201);
    }

    public function update(Request $request, RecruitmentCycle $cycle): JsonResponse
    {
        $cycle->fill($this->validated($request));
        $this->audit->recordChanges('cycle.updated', $cycle);
        $cycle->save();

        return response()->json($cycle);
    }

    public function destroy(RecruitmentCycle $cycle): JsonResponse
    {
        abort_if($cycle->advertisements()->exists(), 422, 'This cycle has advertisements and cannot be deleted.');
        $this->audit->record('cycle.deleted', $cycle, $cycle->toArray());
        $cycle->delete();

        return response()->json(['deleted' => true]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'year' => ['required', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ]);
    }
}
