<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ChecklistItem;
use App\Models\DocumentRequirement;
use Illuminate\Support\Collection;

/**
 * Turns an advertisement's configured requirements into the concrete list
 * that applies to one application, given what the candidate has declared.
 */
class RequirementResolver
{
    /**
     * Document types that belong to one row of a repeatable section (the SPA's ROW_DOCUMENTS):
     * code => [relation on Application, morph alias]. A mandatory requirement of such a type
     * means one upload per row — the paper form asks for certificates of *each* qualification.
     */
    public const ROW_DOCUMENT_TYPES = [
        'qualification_certificate' => ['qualifications', 'qualification'],
        'experience_certificate' => ['employments', 'employment'],
        'merit_certificate' => ['meritRecords', 'merit_record'],
        'training_certificate' => ['trainings', 'training'],
        'membership_certificate' => ['memberships', 'membership'],
    ];

    public function __construct(private FeeCalculator $fees) {}

    /** @return Collection<int, DocumentRequirement> requirements whose condition holds */
    public function documentRequirements(Application $application): Collection
    {
        $application->loadMissing(['personal', 'employments', 'declarations', 'post.advertisement']);

        return DocumentRequirement::query()
            ->with('documentType')
            ->where('advertisement_id', $application->advertisement_id)
            ->where(fn ($q) => $q->whereNull('post_id')->orWhere('post_id', $application->post_id))
            ->get()
            ->filter(fn (DocumentRequirement $r) => $this->conditionHolds($r->condition, $application))
            // Fee-step documents (payment proof) never apply when no fee is payable, whatever their condition.
            ->reject(fn (DocumentRequirement $r) => $r->documentType->step === 'payment' && $this->fees->amountDue($application) <= 0)
            ->values();
    }

    /** Requirements that are mandatory (the requirement's override, else the document type's default) and have no upload yet. */
    public function missingDocuments(Application $application): Collection
    {
        $documents = $application->documents()->get(['document_type_id', 'documentable_type', 'documentable_id']);
        $uploadedTypeIds = $documents->pluck('document_type_id')->unique();

        return $this->documentRequirements($application)
            ->filter(function (DocumentRequirement $r) use ($application, $documents, $uploadedTypeIds) {
                if (! $r->isMandatory()) {
                    return false;
                }
                $rows = $this->rowsWithoutDocument($r, $application, $documents);

                return $rows === null ? ! $uploadedTypeIds->contains($r->document_type_id) : $rows->isNotEmpty();
            })
            ->values();
    }

    /**
     * For a per-row document type, the rows of this application that still have no upload of that
     * type attached. Null for ordinary (application-level) document types.
     */
    public function rowsWithoutDocument(DocumentRequirement $r, Application $application, ?Collection $documents = null): ?Collection
    {
        $map = self::ROW_DOCUMENT_TYPES[$r->documentType->code] ?? null;
        if (! $map) {
            return null;
        }
        [$relation, $morph] = $map;
        $documents ??= $application->documents()->get(['document_type_id', 'documentable_type', 'documentable_id']);
        $covered = $documents->where('document_type_id', $r->document_type_id)->where('documentable_type', $morph)->pluck('documentable_id');

        return $application->$relation()->get()->reject(fn ($row) => $covered->contains($row->id))->values();
    }

    public function conditionHolds(string $condition, Application $application): bool
    {
        $p = $application->personal;

        return match ($condition) {
            'always' => true,
            'reserved_category' => $p && $p->category && $p->category !== 'UR',
            'pwd' => (bool) $p?->is_pwd,
            'ex_serviceman' => (bool) $p?->is_ex_serviceman,
            'in_service' => $application->is_in_service,
            'has_employment' => $application->employments->isNotEmpty(),
            'criminal_disclosure' => $application->declarations
                ->whereIn('code', ['sec20', 'sec26a', 'sec26b', 'sec27'])->where('answer', true)->isNotEmpty(),
            'fee_payable' => $this->fees->amountDue($application) > 0,
            default => false,
        };
    }

    /**
     * Checklist items for the application's post: every template assigned to
     * the advertisement as a whole, plus every template assigned to this post.
     *
     * @return Collection<int, ChecklistItem>
     */
    public function checklistItems(Application $application, bool $applicantVisibleOnly = false): Collection
    {
        return ChecklistItem::query()
            ->with('documentType')
            ->whereIn('checklist_template_id', function ($q) use ($application) {
                $q->select('checklist_template_id')->from('checklist_assignments')
                    ->where('advertisement_id', $application->advertisement_id)
                    ->where(fn ($p) => $p->whereNull('post_id')->orWhere('post_id', $application->post_id));
            })
            ->when($applicantVisibleOnly, fn ($q) => $q->where('applicant_visible', true))
            ->orderBy('checklist_template_id')
            ->orderBy('sort_order')
            ->get();
    }

    public function endorsementRequired(Application $application): bool
    {
        $mode = $application->post->effectiveEndorsementMode();

        return $mode === 'required_for_in_service' && $application->is_in_service;
    }

    public function endorsementApplicable(Application $application): bool
    {
        $mode = $application->post->effectiveEndorsementMode();

        return $mode !== 'not_applicable' && $application->is_in_service;
    }
}
