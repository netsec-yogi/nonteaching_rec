<?php

namespace App\Services\ApplicationForm\Sections;

use App\Models\Application;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Section 21 — Certificates of excellence / merit during the service period. */
class MeritSection extends RepeatableOptionalSection
{
    public function key(): string
    {
        return 'merit';
    }

    public function title(): string
    {
        return 'Certificates of Excellence and Merit';
    }

    public function formReference(): string
    {
        return '21';
    }

    protected function flag(): string
    {
        return 'has_merit_records';
    }

    protected function question(): string
    {
        return 'State whether you have received any certificate of excellence or merit (Section 21).';
    }

    protected function dateFields(): array
    {
        return ['awarded_on'];
    }

    protected function relation(Application $application): HasMany
    {
        return $application->meritRecords();
    }

    protected function rowRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:250'],
            'awarded_by' => ['required', 'string', 'max:250'],
            'awarded_on' => ['nullable', 'date', 'before_or_equal:today'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
