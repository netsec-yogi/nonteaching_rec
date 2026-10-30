<?php

namespace App\Services\ApplicationForm;

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\Employment;
use App\Models\Qualification;
use App\Models\Training;
use App\Services\ApplicationForm\Sections\DeclarationsSection;
use App\Services\RequirementResolver;
use App\Services\SettingsService;
use App\Support\IdentityNumbers;

/**
 * A complete, human-readable rendering of an application in the order of the
 * paper form. It is what gets frozen into application_versions at submission,
 * what the PDF prints, and what reviewers read — so all three always agree.
 * Referee recommendations are deliberately not part of it.
 */
class SnapshotBuilder
{
    public function __construct(
        private ApplicationFormService $form,
        private RequirementResolver $requirements,
        private SettingsService $settings,
    ) {}

    public function build(Application $application): array
    {
        $this->form->load($application);
        $a = $application;
        $p = $a->personal;
        $age = $a->age();
        $yesNo = fn (?bool $v) => $v === null ? '—' : ($v ? 'Yes' : 'No');

        return [
            'meta' => [
                'reference_no' => $a->reference_no,
                'uuid' => $a->uuid,
                'version' => $a->version,
                'status' => $a->status,
                'submitted_at' => $a->submitted_at?->toIso8601String(),
                'generated_at' => now()->toIso8601String(),
                'institute' => $this->settings->get('institute.name'),
                'institute_address' => $this->settings->get('institute.address'),
            ],
            'payment' => [
                'method' => $a->payment?->method,
                'utr_number' => $a->payment?->utr_number,
                'transaction_date' => $a->payment?->transaction_date?->toDateString(),
                'amount' => $a->payment?->amount !== null ? (float) $a->payment->amount : null,
                'amount_due' => $a->payment?->amount_due !== null ? (float) $a->payment->amount_due : null,
                'bank_name' => $a->payment?->bank_name,
                'exemption_basis' => $a->payment?->exemption_basis,
            ],
            'application' => [
                'advertisement_number' => $a->advertisement->number,
                'advertisement_title' => $a->advertisement->title,
                'application_date' => $a->application_date?->toDateString(),
                'post' => $a->post->name,
                'post_code' => $a->post->code,
                'department' => $a->post->department,
            ],
            'personal' => [
                'salutation' => $p?->salutation,
                'full_name' => $p?->full_name,
                'relation_label' => $p?->relation_type === 'husband' ? "Husband's Name" : "Father's Name",
                'relation_name' => $p?->relation_name,
                'date_of_birth' => $p?->date_of_birth?->toDateString(),
                // Frozen with the version, so a regenerated PDF keeps the age and the date it was reckoned on.
                'age' => $age['text'],
                'age_years' => $age['years'],
                'age_months' => $age['months'],
                'age_reference_date' => $age['reference_date'],
                'age_reference_basis' => $age['reference_basis'],
                'place_of_birth' => $p?->place_of_birth,
                'mobile' => $p?->mobile,
                'email' => $p?->email,
                'nationality' => $p?->nationality,
                'gender' => config('recruitment.genders')[$p?->gender] ?? $p?->gender,
                'marital_status' => config('recruitment.marital_statuses')[$p?->marital_status] ?? $p?->marital_status,
                'category' => $p?->category,
                'is_pwd' => $yesNo($p?->is_pwd),
                'disability_type' => $p?->disability_type,
                'disability_percentage' => $p?->disability_percentage,
                'nearest_railway_station' => $p?->nearest_railway_station,
                // Never the full numbers: the snapshot feeds the PDF, previews and every staff view.
                'pan_masked' => IdentityNumbers::maskPan($p?->pan_number),
                'aadhaar_masked' => IdentityNumbers::maskAadhaar($p?->aadhaar_number),
                'correspondence_address' => $a->address('correspondence')?->formatted(),
                'permanent_address' => $a->address('permanent')?->formatted(),
                'is_ex_serviceman' => $yesNo($p?->is_ex_serviceman),
                'ex_serviceman_category' => $p?->ex_serviceman_category,
                'ex_serviceman_details' => $p?->ex_serviceman_details,
            ],
            'qualifications' => $a->qualifications->map(fn (Qualification $q) => [
                'level' => Qualification::LEVELS[$q->level] ?? $q->level,
                'examination' => $q->examination,
                'board_university' => $q->board_university,
                'duration' => $q->duration,
                'year_of_passing' => $q->year_of_passing,
                'division' => $q->division,
                'percentage' => $q->percentage !== null ? (float) $q->percentage : null,
                'subjects' => $q->subjects,
                'specialization' => $q->specialization,
            ])->all(),
            'has_employment' => $a->has_employment,
            'is_in_service' => $a->is_in_service,
            'employments' => $a->employments->map(fn (Employment $e) => [
                'organization' => $e->organization,
                'post_held' => $e->post_held,
                'employment_type' => Employment::TYPES[$e->employment_type] ?? $e->employment_type,
                'from_date' => $e->from_date?->toDateString(),
                'to_date' => $e->is_current ? null : $e->to_date?->toDateString(),
                'is_current' => $e->is_current,
                'duration' => intdiv($e->duration_months, 12).' yr '.($e->duration_months % 12).' mo',
                'duration_months' => $e->duration_months,
                'pay_scale_level' => $e->pay_scale_level,
                'basic_pay' => $e->basic_pay !== null ? (float) $e->basic_pay : null,
                'gross_pay' => $e->gross_pay !== null ? (float) $e->gross_pay : null,
                'nature_of_duties' => $e->nature_of_duties,
            ])->all(),
            'total_experience_months' => (int) $a->employments->sum('duration_months'),
            'section20' => $this->declaration($a, 'sec20', 'Have you ever been convicted by a court of Law or is there any criminal case/disciplinary action/vigilance enquiry pending against you?'),
            'has_merit_records' => $a->has_merit_records,
            'merit_records' => $a->meritRecords->map(fn ($m) => [
                'title' => $m->title, 'awarded_by' => $m->awarded_by,
                'awarded_on' => $m->awarded_on?->toDateString(), 'description' => $m->description,
            ])->all(),
            'has_training_records' => $a->has_training_records,
            'trainings' => $a->trainings->map(fn ($t) => [
                'type' => Training::TYPES[$t->type] ?? $t->type, 'title' => $t->title, 'organization' => $t->organization,
                'from_date' => $t->from_date?->toDateString(), 'to_date' => $t->to_date?->toDateString(),
                'duration' => $t->duration, 'description' => $t->description,
            ])->all(),
            'has_memberships' => $a->has_memberships,
            'memberships' => $a->memberships->map(fn ($m) => [
                'institute_name' => $m->institute_name, 'membership_category' => $m->membership_category,
                'membership_number' => $m->membership_number, 'member_since' => $m->member_since?->toDateString(),
            ])->all(),
            'suitability_statement' => $a->suitability_statement,
            'referees' => $a->referees->map(fn ($r) => $r->only([
                'position', 'name', 'designation', 'organization', 'address', 'phone', 'email',
                'relationship', 'years_known', 'is_resident_of_india', 'is_not_relative', 'is_employer_or_superior',
            ]))->all(),
            'declarations' => collect(DeclarationsSection::QUESTIONS)->map(fn ($q, $code) => $this->declaration($a, $code, $q[0]))->all(),
            'other_achievements' => $a->other_achievements,
            'candidate_declaration' => [
                'text' => $this->settings->get('declaration.text'),
                'version' => $a->declaration_version,
                'place' => $a->declaration_place,
                'date' => $a->declaration_date?->toDateString(),
                'accepted_at' => $a->declaration_accepted_at?->toIso8601String(),
            ],
            'endorsement' => $this->requirements->endorsementApplicable($a) && $a->endorsement ? [
                ...$a->endorsement->only([
                    'employer_organization', 'designation', 'pay_scale_level', 'forwarding_officer_name',
                    'forwarding_officer_designation', 'place', 'no_disciplinary_case_certified', 'no_objection_certified',
                ]),
                'employment_type' => Employment::TYPES[$a->endorsement->employment_type] ?? $a->endorsement->employment_type,
                'employment_start_date' => $a->endorsement->employment_start_date?->toDateString(),
                'basic_pay' => $a->endorsement->basic_pay !== null ? (float) $a->endorsement->basic_pay : null,
                'next_increment_date' => $a->endorsement->next_increment_date?->toDateString(),
                'endorsed_on' => $a->endorsement->endorsed_on?->toDateString(),
            ] : null,
            'checklist' => $this->requirements->checklistItems($a, true)->map(function ($item) use ($a) {
                $entry = $a->checklistEntries->firstWhere('checklist_item_id', $item->id);
                $doc = $entry?->document_id ? $a->documents->firstWhere('id', $entry->document_id) : null;

                return [
                    'label' => $item->label,
                    'status' => match ($entry?->candidate_status) {
                        'yes' => '✓', 'no' => 'X', 'na' => 'NA', default => '—'
                    },
                    'page_reference' => $entry?->page_reference,
                    'document' => $doc?->original_name,
                ];
            })->values()->all(),
            'documents' => $a->documents->map(fn (ApplicationDocument $d) => [
                'id' => $d->id,
                'uuid' => $d->uuid,
                'type' => $d->documentType?->name,
                'label' => $d->label,
                'upload_type' => $d->upload_type,
                'original_name' => $d->original_name,
                'size' => $d->size,
                'sha256' => $d->sha256,
                'uploaded_at' => $d->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    private function declaration(Application $a, string $code, string $question): array
    {
        $d = $a->declaration($code);

        return ['code' => $code, 'question' => $question, 'answer' => $d?->answer === null ? null : ($d->answer ? 'YES' : 'NO'), 'details' => $d?->details];
    }
}
