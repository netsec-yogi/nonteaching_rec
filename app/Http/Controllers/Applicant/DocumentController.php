<?php

namespace App\Http\Controllers\Applicant;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\DocumentType;
use App\Services\ApplicationForm\ApplicationFormService;
use App\Services\DocumentService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DocumentController extends Controller
{
    /** Repeatable rows a document may be attached to. */
    private const ATTACHABLE = ['qualification', 'employment', 'merit_record', 'training', 'membership'];

    public function __construct(private DocumentService $documents, private ApplicationFormService $form) {}

    public function store(Request $request, Application $application): JsonResponse
    {
        $this->authorize('edit', $application);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'document_type' => ['required', Rule::exists('document_types', 'code')->where('is_active', true)],
            'documentable_type' => ['nullable', Rule::in(self::ATTACHABLE)],
            'documentable_id' => ['nullable', 'required_with:documentable_type', 'integer'],
            'label' => ['nullable', 'string', 'max:250'],
            'replace_uuid' => ['nullable', 'uuid'],
        ]);

        $documentable = null;
        if (! empty($data['documentable_type'])) {
            $class = Relation::getMorphedModel($data['documentable_type']);
            $documentable = $class::where('application_id', $application->id)->findOrFail($data['documentable_id']);
        }

        $type = DocumentType::where('code', $data['document_type'])->firstOrFail();
        // After a reopening only the sections opened for correction may change — uploads included.
        $this->form->assertEditable($application, $type->step);
        $document = $this->documents->store($application, $type, $request->file('file'), $request->user(), $documentable, $data['label'] ?? null);

        // Replacing a single-file slot (e.g. the photograph) removes the earlier draft upload.
        if (! empty($data['replace_uuid'])) {
            $old = $application->documents()->where('uuid', $data['replace_uuid'])->where('is_frozen', false)->first();
            if ($old) {
                $this->documents->delete($old);
            }
        }

        return response()->json($this->form->documentSummary($document->load('documentType')), 201);
    }

    public function destroy(Application $application, ApplicationDocument $document): JsonResponse
    {
        $this->authorize('edit', $application);
        abort_unless($document->application_id === $application->id, 404);
        $this->form->assertEditable($application, $document->documentType?->step);
        $this->documents->delete($document);

        return response()->json(['deleted' => true]);
    }

    /** Shared by applicants and staff; the policy decides. */
    public function download(Request $request, Application $application, ApplicationDocument $document)
    {
        abort_unless($document->application_id === $application->id, 404);
        $this->authorize('downloadDocuments', $application);

        return $this->documents->download($document, $request->user(), $request->boolean('inline'));
    }
}
