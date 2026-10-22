<?php

namespace App\Http\Controllers\Applicant;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ClarificationRequest;
use App\Models\DocumentType;
use App\Services\ScrutinyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClarificationController extends Controller
{
    public function respond(Request $request, Application $application, ClarificationRequest $clarification, ScrutinyService $scrutiny): JsonResponse
    {
        $this->authorize('viewOwn', $application);
        abort_unless($clarification->application_id === $application->id, 404);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*.file' => ['required', 'file', 'max:20480'],
            'files.*.document_type' => ['required', Rule::exists('document_types', 'code')->where('is_active', true)],
            'files.*.label' => ['nullable', 'string', 'max:250'],
        ]);

        $files = collect($data['files'] ?? [])->map(fn ($f, $i) => [
            'file' => $request->file("files.$i.file"),
            'document_type' => DocumentType::where('code', $f['document_type'])->firstOrFail(),
            'label' => $f['label'] ?? null,
        ])->all();

        $scrutiny->respondToClarification($clarification, $request->user(), $data['message'], $files);

        return response()->json(['status' => 'responded']);
    }
}
