<?php

namespace App\Services\ApplicationForm\Sections;

use App\Models\Application;

/** Section 20 — Conviction, criminal case, disciplinary action or vigilance enquiry. */
class CriminalSection extends Section
{
    public function key(): string
    {
        return 'criminal';
    }

    public function title(): string
    {
        return 'Criminal, Disciplinary and Vigilance Information';
    }

    public function formReference(): string
    {
        return '20';
    }

    public function rules(Application $application): array
    {
        return $this->yesNoWithDetailsRules('sec20');
    }

    public function attributes(): array
    {
        return ['sec20.answer' => 'answer', 'sec20.details' => 'details'];
    }

    public function save(Application $application, array $data): void
    {
        $yes = (bool) $data['sec20']['answer'];
        $this->saveDeclaration($application, 'sec20', $yes, $yes ? ($data['sec20']['details'] ?? null) : null);
    }

    public function data(Application $application): array
    {
        return ['sec20' => $this->declarationData($application, 'sec20')];
    }

    public function issues(Application $application): array
    {
        $issues = $application->declaration('sec20')?->answer === null
            ? ['Answer the question on convictions and pending proceedings (Section 20).'] : [];

        return array_merge($issues, $this->missingDocumentIssues($application));
    }
}
