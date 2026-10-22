<?php

namespace App\Services\ApplicationForm\Sections;

use App\Models\Application;

/** Section 31 — Any other achievement not covered above. */
class AchievementsSection extends Section
{
    public function key(): string
    {
        return 'achievements';
    }

    public function title(): string
    {
        return 'Other Achievements';
    }

    public function formReference(): string
    {
        return '31';
    }

    public function rules(Application $application): array
    {
        return ['other_achievements' => ['nullable', 'string', 'max:20000']];
    }

    public function save(Application $application, array $data): void
    {
        $application->forceFill(['other_achievements' => $data['other_achievements'] ?? null])->save();
        $this->markVisited($application);
    }

    public function data(Application $application): array
    {
        return ['other_achievements' => $application->other_achievements];
    }

    public function issues(Application $application): array
    {
        return in_array($this->key(), $application->completed_steps ?? [], true)
            ? $this->missingDocumentIssues($application)
            : ['Review the "Other achievements" step (Section 31), even if you have nothing to add.'];
    }

    private function markVisited(Application $application): void
    {
        $steps = array_values(array_unique([...($application->completed_steps ?? []), $this->key()]));
        $application->forceFill(['completed_steps' => $steps])->save();
    }
}
