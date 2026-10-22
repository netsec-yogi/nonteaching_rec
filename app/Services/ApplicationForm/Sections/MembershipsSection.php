<?php

namespace App\Services\ApplicationForm\Sections;

use App\Models\Application;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Section 23 — Corporate membership of professional institutes. */
class MembershipsSection extends RepeatableOptionalSection
{
    public function key(): string
    {
        return 'memberships';
    }

    public function title(): string
    {
        return 'Corporate Membership of Professional Institutes';
    }

    public function formReference(): string
    {
        return '23';
    }

    protected function flag(): string
    {
        return 'has_memberships';
    }

    protected function question(): string
    {
        return 'State whether you are a corporate member of any professional institute (Section 23).';
    }

    protected function dateFields(): array
    {
        return ['member_since'];
    }

    protected function relation(Application $application): HasMany
    {
        return $application->memberships();
    }

    protected function rowRules(): array
    {
        return [
            'institute_name' => ['required', 'string', 'max:250'],
            'membership_category' => ['required', 'string', 'max:150'],
            'membership_number' => ['required', 'string', 'max:100'],
            'member_since' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }
}
