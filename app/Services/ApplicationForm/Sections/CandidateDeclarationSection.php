<?php

namespace App\Services\ApplicationForm\Sections;

use App\Models\Application;
use App\Services\RequirementResolver;
use App\Services\SettingsService;

/** The declaration on page 3, signed electronically. */
class CandidateDeclarationSection extends Section
{
    public function __construct(RequirementResolver $requirements, private SettingsService $settings)
    {
        parent::__construct($requirements);
    }

    public function key(): string
    {
        return 'declaration';
    }

    public function title(): string
    {
        return 'Declaration';
    }

    public function formReference(): string
    {
        return 'Declaration';
    }

    public function rules(Application $application): array
    {
        return [
            'affirm_true' => ['accepted'],
            'affirm_read_instructions' => ['accepted'],
            'affirm_not_concealed' => ['accepted'],
            'affirm_consequences' => ['accepted'],
            'place' => ['required', 'string', 'max:100'],
            'date' => ['required', 'date', 'before_or_equal:today', 'after_or_equal:-30 days'],
        ];
    }

    public function messages(): array
    {
        return [
            'affirm_true.accepted' => 'You must affirm that the entries are true to the best of your knowledge and belief.',
            'affirm_read_instructions.accepted' => 'You must confirm that you have read and understood the instructions.',
            'affirm_not_concealed.accepted' => 'You must confirm that you have not concealed any material information.',
            'affirm_consequences.accepted' => 'You must acknowledge the consequences of suppression or distortion of facts.',
        ];
    }

    public function save(Application $application, array $data): void
    {
        $application->forceFill([
            'declaration_place' => $data['place'],
            'declaration_date' => $data['date'],
            'declaration_accepted_at' => now(),
            'declaration_version' => $this->settings->get('declaration.version'),
            'declaration_ip' => request()?->ip(),
        ])->save();
    }

    public function data(Application $application): array
    {
        $accepted = $application->declaration_accepted_at !== null;

        return [
            'text' => $this->settings->get('declaration.text'),
            'version' => $this->settings->get('declaration.version'),
            'affirm_true' => $accepted,
            'affirm_read_instructions' => $accepted,
            'affirm_not_concealed' => $accepted,
            'affirm_consequences' => $accepted,
            'place' => $application->declaration_place,
            'date' => ($application->declaration_date ?? now())->toDateString(),
            'accepted_at' => $application->declaration_accepted_at?->toIso8601String(),
        ];
    }

    public function issues(Application $application): array
    {
        if (! $application->declaration_accepted_at) {
            return ['Accept the declaration.'];
        }
        if ($application->declaration_version !== $this->settings->get('declaration.version')) {
            return ['The declaration text has been updated. Read and accept it again.'];
        }

        return [];
    }
}
