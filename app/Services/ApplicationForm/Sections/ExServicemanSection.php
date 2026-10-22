<?php

namespace App\Services\ApplicationForm\Sections;

use App\Models\Application;

/** Section 17 — Ex-servicemen sub-category. */
class ExServicemanSection extends Section
{
    public function key(): string
    {
        return 'ex_serviceman';
    }

    public function title(): string
    {
        return 'Ex-Servicemen';
    }

    public function formReference(): string
    {
        return '17';
    }

    public function rules(Application $application): array
    {
        return [
            'is_ex_serviceman' => ['required', 'boolean'],
            'ex_serviceman_category' => ['required_if_accepted:is_ex_serviceman', 'nullable', 'string', 'max:150'],
            'ex_serviceman_details' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return ['ex_serviceman_category' => 'ex-servicemen category'];
    }

    public function save(Application $application, array $data): void
    {
        $yes = (bool) $data['is_ex_serviceman'];
        $application->personal()->updateOrCreate([], [
            'is_ex_serviceman' => $yes,
            'ex_serviceman_category' => $yes ? $data['ex_serviceman_category'] : null,
            'ex_serviceman_details' => $yes ? ($data['ex_serviceman_details'] ?? null) : null,
        ]);
    }

    public function data(Application $application): array
    {
        $p = $application->personal;

        return [
            'is_ex_serviceman' => $p?->is_ex_serviceman,
            'ex_serviceman_category' => $p?->ex_serviceman_category,
            'ex_serviceman_details' => $p?->ex_serviceman_details,
        ];
    }

    public function issues(Application $application): array
    {
        $issues = $application->personal?->is_ex_serviceman === null
            ? ['State whether you belong to the ex-servicemen sub-category.'] : [];

        return array_merge($issues, $this->missingDocumentIssues($application));
    }
}
