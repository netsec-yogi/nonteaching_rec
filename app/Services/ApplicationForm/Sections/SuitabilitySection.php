<?php

namespace App\Services\ApplicationForm\Sections;

use App\Models\Application;
use App\Rules\MaxWords;

/** Section 24 — Paragraph (not exceeding 500 words) justifying suitability for the post. */
class SuitabilitySection extends Section
{
    public const MAX_WORDS = 500;

    public function key(): string
    {
        return 'suitability';
    }

    public function title(): string
    {
        return 'Suitability for the Post';
    }

    public function formReference(): string
    {
        return '24';
    }

    public function rules(Application $application): array
    {
        return ['suitability_statement' => ['required', 'string', 'max:10000', new MaxWords(self::MAX_WORDS)]];
    }

    public function save(Application $application, array $data): void
    {
        $application->forceFill(['suitability_statement' => trim($data['suitability_statement'])])->save();
    }

    public function data(Application $application): array
    {
        return ['suitability_statement' => $application->suitability_statement, 'max_words' => self::MAX_WORDS];
    }

    public function issues(Application $application): array
    {
        return blank($application->suitability_statement)
            ? ['Write the paragraph on your suitability for the post (Section 24).'] : [];
    }
}
