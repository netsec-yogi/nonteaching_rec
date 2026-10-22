<?php

namespace App\Services\ApplicationForm\Sections;

use App\Models\Application;
use App\Models\Employment;
use Carbon\Carbon;
use Illuminate\Validation\Rule;

/** Section 19 — Employment, in reverse chronological order. */
class EmploymentSection extends Section
{
    private const FIELDS = ['organization', 'post_held', 'employment_type', 'from_date', 'to_date', 'is_current', 'pay_scale_level', 'basic_pay', 'gross_pay', 'nature_of_duties'];

    public function key(): string
    {
        return 'employment';
    }

    public function title(): string
    {
        return 'Employment History';
    }

    public function formReference(): string
    {
        return '19';
    }

    public function rules(Application $application): array
    {
        return [
            'has_employment' => ['required', 'boolean'],
            'is_in_service' => ['required', 'boolean'],
            'rows' => ['exclude_unless:has_employment,true', 'required', 'array', 'min:1', 'max:40'],
            'rows.*.id' => ['nullable', 'integer'],
            'rows.*.organization' => ['required', 'string', 'max:250'],
            'rows.*.post_held' => ['required', 'string', 'max:200'],
            'rows.*.employment_type' => ['required', Rule::in(array_keys(Employment::TYPES))],
            'rows.*.from_date' => ['required', 'date', 'before_or_equal:today'],
            'rows.*.is_current' => ['boolean'],
            'rows.*.to_date' => ['exclude_if:rows.*.is_current,true', 'required', 'date', 'after_or_equal:rows.*.from_date', 'before_or_equal:today'],
            'rows.*.pay_scale_level' => ['nullable', 'string', 'max:150'],
            'rows.*.basic_pay' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'rows.*.gross_pay' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'rows.*.nature_of_duties' => ['required', 'string', 'max:3000'],
        ];
    }

    public function messages(): array
    {
        return [
            'rows.*.to_date.required' => 'Enter the end date, or tick "Currently working here".',
            'rows.*.to_date.after_or_equal' => 'The end date cannot be before the start date.',
        ];
    }

    public function attributes(): array
    {
        return [
            'rows.*.organization' => 'department / institute',
            'rows.*.post_held' => 'post held',
            'rows.*.from_date' => 'start date',
            'rows.*.nature_of_duties' => 'nature of duty',
        ];
    }

    public function save(Application $application, array $data): void
    {
        $hasEmployment = (bool) $data['has_employment'];
        $application->forceFill([
            'has_employment' => $hasEmployment,
            'is_in_service' => (bool) $data['is_in_service'],
        ])->save();

        // Stored most recent first: current posts, then by start date descending.
        $rows = $hasEmployment ? array_values($data['rows']) : [];
        usort($rows, fn ($a, $b) => [! empty($b['is_current']), $b['from_date']] <=> [! empty($a['is_current']), $a['from_date']]);

        $this->syncRows($application, $application->employments(), $rows, function (array $row) {
            $current = (bool) ($row['is_current'] ?? false);
            $from = Carbon::parse($row['from_date']);
            $to = $current ? now() : Carbon::parse($row['to_date']);

            return array_merge(array_fill_keys(self::FIELDS, null), array_intersect_key($row, array_flip(self::FIELDS)), [
                'is_current' => $current,
                'to_date' => $current ? null : $row['to_date'],
                'duration_months' => self::months($from, $to),
            ]);
        });
    }

    /** Whole months between two dates, counting a part month from the 15th onward. */
    public static function months(Carbon $from, Carbon $to): int
    {
        $months = $from->diffInMonths($to, false);

        return max(0, (int) floor($months + ($months - floor($months) >= 0.5 ? 1 : 0)));
    }

    public function data(Application $application): array
    {
        return [
            'has_employment' => $application->has_employment,
            'is_in_service' => $application->is_in_service,
            'rows' => $application->employments->map(fn (Employment $e) => [
                'id' => $e->id,
                ...$e->only(['organization', 'post_held', 'employment_type', 'is_current', 'pay_scale_level', 'nature_of_duties', 'duration_months']),
                'from_date' => $e->from_date?->toDateString(),
                'to_date' => $e->to_date?->toDateString(),
                'basic_pay' => $e->basic_pay !== null ? (float) $e->basic_pay : null,
                'gross_pay' => $e->gross_pay !== null ? (float) $e->gross_pay : null,
            ])->all(),
        ];
    }

    public function issues(Application $application): array
    {
        $issues = [];
        if ($application->has_employment === null) {
            $issues[] = 'State whether you have any employment history.';
        } elseif ($application->has_employment && $application->employments->isEmpty()) {
            $issues[] = 'Add your employment details.';
        }

        return array_merge($issues, $this->missingDocumentIssues($application));
    }
}
