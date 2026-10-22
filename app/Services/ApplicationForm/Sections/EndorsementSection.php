<?php

namespace App\Services\ApplicationForm\Sections;

use App\Models\Application;
use App\Models\Employment;
use Illuminate\Validation\Rule;

/** Section 32 — Endorsement by the employer (in-service candidates). */
class EndorsementSection extends Section
{
    private const FIELDS = [
        'employer_organization', 'designation', 'employment_type', 'employment_start_date', 'pay_scale_level',
        'basic_pay', 'next_increment_date', 'no_disciplinary_case_certified', 'no_objection_certified',
        'forwarding_officer_name', 'forwarding_officer_designation', 'place', 'endorsed_on',
    ];

    public function key(): string
    {
        return 'endorsement';
    }

    public function title(): string
    {
        return 'Employer Endorsement';
    }

    public function formReference(): string
    {
        return '32';
    }

    public function isApplicable(Application $application): bool
    {
        return $this->requirements->endorsementApplicable($application);
    }

    public function rules(Application $application): array
    {
        if (! $this->isApplicable($application)) {
            return [];
        }
        $req = $this->requirements->endorsementRequired($application) ? 'required' : 'nullable';

        return [
            'employer_organization' => [$req, 'string', 'max:250'],
            'designation' => [$req, 'string', 'max:200'],
            'employment_type' => [$req, Rule::in(array_keys(Employment::TYPES))],
            'employment_start_date' => [$req, 'date', 'before_or_equal:today'],
            'pay_scale_level' => [$req, 'string', 'max:150'],
            'basic_pay' => [$req, 'numeric', 'min:0', 'max:10000000'],
            'next_increment_date' => ['nullable', 'date'],
            'no_disciplinary_case_certified' => [$req === 'required' ? 'accepted' : 'boolean'],
            'no_objection_certified' => [$req === 'required' ? 'accepted' : 'boolean'],
            'forwarding_officer_name' => [$req, 'string', 'max:200'],
            'forwarding_officer_designation' => [$req, 'string', 'max:200'],
            'place' => [$req, 'string', 'max:100'],
            'endorsed_on' => [$req, 'date', 'before_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'no_disciplinary_case_certified.accepted' => 'The endorsement must certify that no disciplinary/vigilance case has been held, contemplated or is pending.',
            'no_objection_certified.accepted' => 'The endorsement must state that the employer has no objection.',
        ];
    }

    public function save(Application $application, array $data): void
    {
        if (! $this->isApplicable($application)) {
            return;
        }
        $values = array_intersect_key($data, array_flip(self::FIELDS));
        $values['no_disciplinary_case_certified'] = (bool) ($values['no_disciplinary_case_certified'] ?? false);
        $values['no_objection_certified'] = (bool) ($values['no_objection_certified'] ?? false);
        $application->endorsement()->updateOrCreate([], $values);
    }

    public function data(Application $application): array
    {
        $e = $application->endorsement;
        $current = $application->employments->firstWhere('is_current', true);

        $data = ['applicable' => $this->isApplicable($application), 'required' => $this->requirements->endorsementRequired($application)];
        foreach (self::FIELDS as $f) {
            $data[$f] = $e?->$f;
        }
        foreach (['employment_start_date', 'next_increment_date', 'endorsed_on'] as $f) {
            $data[$f] = $e?->$f?->toDateString();
        }
        $data['basic_pay'] = $e?->basic_pay !== null ? (float) $e->basic_pay : null;

        // Prefill from the current employment row.
        if (! $e && $current) {
            $data['employer_organization'] = $current->organization;
            $data['designation'] = $current->post_held;
            $data['employment_type'] = $current->employment_type;
            $data['employment_start_date'] = $current->from_date?->toDateString();
            $data['pay_scale_level'] = $current->pay_scale_level;
            $data['basic_pay'] = $current->basic_pay !== null ? (float) $current->basic_pay : null;
        }
        $data['instructions'] = $application->advertisement->endorsement_instructions;

        return $data;
    }

    public function issues(Application $application): array
    {
        if (! $this->requirements->endorsementRequired($application)) {
            return [];
        }
        $e = $application->endorsement;
        $issues = (! $e || ! $e->forwarding_officer_name || ! $e->no_objection_certified)
            ? ['Complete the employer endorsement (Section 32) — it is mandatory for in-service candidates under this advertisement.'] : [];

        return array_merge($issues, $this->missingDocumentIssues($application));
    }
}
