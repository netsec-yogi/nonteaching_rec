<?php

namespace App\Services\ApplicationForm\Sections;

use App\Models\Application;
use App\Models\ChecklistItem;
use Illuminate\Validation\Rule;

/** The check list on page 5. Items come from the checklist templates assigned to the post. */
class ChecklistSection extends Section
{
    public function key(): string
    {
        return 'checklist';
    }

    public function title(): string
    {
        return 'Application Checklist';
    }

    public function formReference(): string
    {
        return 'Check List';
    }

    public function rules(Application $application): array
    {
        $itemIds = $this->requirements->checklistItems($application, true)->pluck('id')->all();

        return [
            'entries' => ['present', 'array'],
            'entries.*.checklist_item_id' => ['required', 'integer', Rule::in($itemIds)],
            'entries.*.candidate_status' => ['nullable', Rule::in(['yes', 'no', 'na'])],
            'entries.*.page_reference' => ['nullable', 'string', 'max:100'],
            'entries.*.document_id' => ['nullable', 'integer', Rule::exists('application_documents', 'id')
                ->where('application_id', $application->id)->whereNull('deleted_at')],
        ];
    }

    public function save(Application $application, array $data): void
    {
        foreach ($data['entries'] as $entry) {
            $application->checklistEntries()->updateOrCreate(
                ['checklist_item_id' => $entry['checklist_item_id']],
                [
                    'candidate_status' => $entry['candidate_status'] ?? null,
                    'page_reference' => $entry['page_reference'] ?? null,
                    'document_id' => $entry['document_id'] ?? null,
                ],
            );
        }
    }

    public function data(Application $application): array
    {
        $entries = $application->checklistEntries->keyBy('checklist_item_id');

        return ['entries' => $this->requirements->checklistItems($application, true)->map(fn (ChecklistItem $item) => [
            'checklist_item_id' => $item->id,
            'label' => $item->label,
            'is_mandatory' => $item->is_mandatory,
            'document_type_code' => $item->documentType?->code,
            'candidate_status' => $entries[$item->id]->candidate_status ?? null,
            'page_reference' => $entries[$item->id]->page_reference ?? null,
            'document_id' => $entries[$item->id]->document_id ?? null,
        ])->values()->all()];
    }

    public function issues(Application $application): array
    {
        $entries = $application->checklistEntries->keyBy('checklist_item_id');
        $unanswered = $this->requirements->checklistItems($application, true)
            ->filter(fn (ChecklistItem $i) => empty($entries[$i->id]?->candidate_status));

        $issues = $unanswered->isNotEmpty()
            ? ['Mark every checklist item as enclosed (✓), not enclosed (X) or not applicable (NA).'] : [];

        // Documents with no step of their own are collected on the checklist.
        $missing = $this->requirements->missingDocuments($application)
            ->filter(fn ($r) => $r->documentType->step === null || $r->documentType->step === 'checklist')
            ->map(fn ($r) => 'Upload required: '.$r->documentType->name.'.');

        return array_merge($issues, $missing->values()->all());
    }
}
