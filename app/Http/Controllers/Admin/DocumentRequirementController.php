<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Advertisement;
use App\Models\DocumentRequirement;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DocumentRequirementController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Advertisement $advertisement): JsonResponse
    {
        return response()->json([
            'requirements' => $advertisement->documentRequirements()->with(['documentType', 'post:id,code,name'])->get(),
            'conditions' => DocumentRequirement::CONDITIONS,
        ]);
    }

    public function store(Request $request, Advertisement $advertisement): JsonResponse
    {
        $req = $advertisement->documentRequirements()->create($this->validated($request, $advertisement));
        $this->audit->record('document_requirement.created', $req, [], $req->toArray());

        return response()->json($req->load('documentType'), 201);
    }

    public function update(Request $request, Advertisement $advertisement, DocumentRequirement $requirement): JsonResponse
    {
        abort_unless($requirement->advertisement_id === $advertisement->id, 404);
        $requirement->fill($this->validated($request, $advertisement));
        $this->audit->recordChanges('document_requirement.updated', $requirement);
        $requirement->save();

        return response()->json($requirement->load('documentType'));
    }

    public function destroy(Advertisement $advertisement, DocumentRequirement $requirement): JsonResponse
    {
        abort_unless($requirement->advertisement_id === $advertisement->id, 404);
        $this->audit->record('document_requirement.deleted', $requirement, $requirement->toArray());
        $requirement->delete();

        return response()->json(['deleted' => true]);
    }

    private function validated(Request $request, Advertisement $ad): array
    {
        return $request->validate([
            'post_id' => ['nullable', Rule::exists('posts', 'id')->where('advertisement_id', $ad->id)],
            'document_type_id' => ['required', 'exists:document_types,id'],
            'condition' => ['required', Rule::in(array_keys(DocumentRequirement::CONDITIONS))],
            'is_mandatory' => ['nullable', 'boolean'], // null = follow the document type's default
            'accepts_undertaking' => ['nullable', 'boolean'], // null = follow the document type
            'instructions' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
