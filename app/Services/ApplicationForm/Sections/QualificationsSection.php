<?php

namespace App\Services\ApplicationForm\Sections;

use App\Models\Application;
use App\Models\Qualification;
use Illuminate\Validation\Rule;

/** Section 18 — Educational / Professional / Technical qualifications. */
class QualificationsSection extends Section
{
    private const FIELDS = ['level', 'examination', 'board_university', 'duration', 'year_of_passing', 'division', 'percentage', 'subjects', 'specialization'];

    public function key(): string
    {
        return 'qualifications';
    }

    public function title(): string
    {
        return 'Educational, Professional and Technical Qualifications';
    }

    public function formReference(): string
    {
        return '18';
    }

    public function rules(Application $application): array
    {
        $year = (int) now()->format('Y');

        return [
            'rows' => ['required', 'array', 'min:1', 'max:30'],
            'rows.*.id' => ['nullable', 'integer'],
            'rows.*.level' => ['required', Rule::in(array_keys(Qualification::LEVELS))],
            'rows.*.examination' => ['required', 'string', 'max:200'],
            'rows.*.board_university' => ['required', 'string', 'max:250'],
            'rows.*.duration' => ['nullable', 'string', 'max:60'],
            'rows.*.year_of_passing' => ['required', 'integer', 'between:1950,'.$year],
            'rows.*.division' => ['nullable', 'string', 'max:30'],
            'rows.*.percentage' => ['nullable', 'numeric', 'between:0,100'],
            'rows.*.subjects' => ['nullable', 'string', 'max:500'],
            'rows.*.specialization' => ['nullable', 'string', 'max:250'],
        ];
    }

    public function attributes(): array
    {
        return [
            'rows.*.examination' => 'examination passed',
            'rows.*.board_university' => 'board / university',
            'rows.*.year_of_passing' => 'year of passing',
        ];
    }

    public function save(Application $application, array $data): void
    {
        $this->syncRows($application, $application->qualifications(), $data['rows'],
            fn (array $row) => array_intersect_key($row, array_flip(self::FIELDS)) + array_fill_keys(self::FIELDS, null));
    }

    public function data(Application $application): array
    {
        return ['rows' => $application->qualifications->map(fn (Qualification $q) => [
            'id' => $q->id,
            ...$q->only(self::FIELDS),
            'percentage' => $q->percentage !== null ? (float) $q->percentage : null,
        ])->all()];
    }

    public function issues(Application $application): array
    {
        $issues = [];
        if ($application->qualifications->isEmpty()) {
            $issues[] = 'Add your educational qualifications, starting from Class 10.';
        } elseif (! $application->qualifications->contains('level', 'class_10')) {
            $issues[] = 'Add your Class 10 / Matriculation details — qualifications start from Class 10.';
        }

        return array_merge($issues, $this->missingDocumentIssues($application));
    }
}
