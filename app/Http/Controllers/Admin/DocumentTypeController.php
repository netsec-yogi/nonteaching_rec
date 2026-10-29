<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
        return response()->json(['types' => DocumentType::orderBy('name')->get(), 'steps' => self::STEPS]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['max_kb'] ??= (int) app(SettingsService::class)->get('uploads.max_kb_default');
        $type = DocumentType::create($data);
        $this->audit->record('document_type.created', $type, [], $type->toArray());

        return response()->json($type, 201);
    }

    public function update(Request $request, DocumentType $documentType): JsonResponse
    {
        $documentType->fill($this->validated($request, $documentType));
        $this->audit->recordChanges('document_type.updated', $documentType);
        $documentType->save();

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
            'is_active' => ['boolean'],
        ]);
    }
}
