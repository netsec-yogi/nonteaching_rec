<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Services\AuditLogger;
use App\Services\TemplateMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailTemplateController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return response()->json(EmailTemplate::orderBy('name')->get());
    }

    public function update(Request $request, EmailTemplate $emailTemplate): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:300'],
            'body' => ['required', 'string', 'max:20000'],
            'is_active' => ['boolean'],
        ]);
        $emailTemplate->fill($data + ['updated_by' => $request->user()->id]);
        $this->audit->recordChanges('email_template.updated', $emailTemplate);
        $emailTemplate->save();

        return response()->json($emailTemplate);
    }

    public function preview(Request $request, EmailTemplate $emailTemplate, TemplateMailer $mailer): JsonResponse
    {
        $draft = new EmailTemplate(['subject' => $request->input('subject', $emailTemplate->subject), 'body' => $request->input('body', $emailTemplate->body)]);
        $sample = collect($emailTemplate->placeholders ?? [])->mapWithKeys(fn ($p) => [$p => str_contains($p, 'url') || str_contains($p, 'link')
            ? 'https://recruitment.example/sample' : '['.strtoupper($p).']'])->all();

        return response()->json($mailer->preview($draft, $sample));
    }
}
