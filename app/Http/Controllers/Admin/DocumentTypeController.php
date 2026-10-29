<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DocumentRequirement;
use App\Models\DocumentType;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DocumentTypeController extends Controller
{
    public const STEPS = ['payment', 'personal', 'ex_serviceman', 'qualifications', 'employment', 'criminal', 'merit',
        'training', 'memberships', 'referees', 'declarations', 'achievements', 'endorsement', 'checklist'];

    public function __construct(private AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        // How many advertisement requirements set their own Mandatory value for each type.
        $overrides = DocumentRequirement::whereNotNull('is_mandatory')->selectRaw('document_type_id, count(*) as n')->groupBy('document_type_id')->pluck('n', 'document_type_id');

        return response()->json([
            'types' => DocumentType::orderBy('name')->get()->map(fn (DocumentType $t) => [...$t->toArray(), 'advertisement_overrides' => (int) ($overrides[$t->id] ?? 0)]),
            'steps' => self::STEPS,
            'conditions' => DocumentRequirement::CONDITIONS,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        unset($data['apply_to_advertisements']);
        $data['max_kb'] ??= (int) app(SettingsService::class)->get('uploads.max_kb_default');
        $type = DocumentType::create($data);
        $this->audit->record('document_type.created', $type, [], $type->toArray());

        return response()->json($type, 201);
    }

    public function update(Request $request, DocumentType $documentType): JsonResponse
    {
        $data = $this->validated($request, $documentType);
        $applyEverywhere = (bool) ($data['apply_to_advertisements'] ?? false);
        unset($data['apply_to_advertisements']);
        $documentType->fill($data);
        $this->audit->recordChanges('document_type.updated', $documentType);
        $documentType->save();

        // Optionally make advertisements that set their own Mandatory value follow this setting again.
        if ($applyEverywhere) {
            $overridden = DocumentRequirement::where('document_type_id', $documentType->id)->whereNotNull('is_mandatory')->get();
            foreach ($overridden as $requirement) {
                $requirement->is_mandatory = null;
                $this->audit->recordChanges('document_requirement.updated', $requirement, ['reason' => 'Applied the document type setting to all advertisements']);
                $requirement->save();
            }
        }

        return response()->json($documentType);
    }

    private function validated(Request $request, ?DocumentType $type = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/', Rule::unique('document_types', 'code')->ignore($type?->id)],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:255'],
            'allowed_mimes' => ['required', 'array', 'min:1'],
            'allowed_mimes.*' => [Rule::in(['application/pdf', 'image/jpeg', 'image/png'])],
            'max_kb' => ['nullable', 'integer', 'between:20,20480'],
            'step' => ['nullable', Rule::in(self::STEPS)],
            'is_mandatory' => ['boolean'],
            'condition' => ['sometimes', Rule::in(array_keys(DocumentRequirement::CONDITIONS))],
            'accepts_undertaking' => ['boolean'],
            'apply_to_advertisements' => ['sometimes', 'boolean'],
            'is_active' => ['boolean'],
        ]);
    }
}
