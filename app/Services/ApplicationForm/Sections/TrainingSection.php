<?php

namespace App\Services\ApplicationForm\Sections;

use App\Models\Application;
use App\Models\Training;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;

/** Section 22 — Training courses conducted / attended, consultancy services offered. */
class TrainingSection extends RepeatableOptionalSection
{
    public function key(): string
    {
        return 'training';
    }

    public function title(): string
    {
        return 'Training, Consultancy and Professional Activities';
    }

    public function formReference(): string
    {
        return '22';
    }

    protected function flag(): string
    {
        return 'has_training_records';
    }

    protected function question(): string
    {
        return 'State whether you have conducted or attended training courses or offered consultancy services (Section 22).';
    }

    protected function dateFields(): array
    {
        return ['from_date', 'to_date'];
    }

    protected function relation(Application $application): HasMany
    {
        return $application->trainings();
    }

    protected function rowRules(): array
    {
        return [
            'type' => ['required', Rule::in(array_keys(Training::TYPES))],
            'title' => ['required', 'string', 'max:250'],
            'organization' => ['nullable', 'string', 'max:250'],
            'from_date' => ['nullable', 'date', 'before_or_equal:today'],
            'to_date' => ['nullable', 'date', 'after_or_equal:rows.*.from_date'],
            'duration' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
