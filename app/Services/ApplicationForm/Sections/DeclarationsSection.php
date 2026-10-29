<?php

namespace App\Services\ApplicationForm\Sections;

use App\Models\Application;

/** Sections 26–30 — Additional declarations. */
class DeclarationsSection extends Section
{
    /** code => [question, details required when answer is] */
    public const QUESTIONS = [
        'sec26a' => ['Have you ever been arrested, prosecuted and kept under detention, or bound down/fined/convicted by a court of law for any offence or debarred/disqualified by any Public Service Commission from appearing at its Exam./Selections or debarred from taking any Exam./rusticated by any University or any other Educational authority/Institution?', true],
        'sec26b' => ['Is any case pending against you in any court of law, University or any other educational authority/Institution?', true],
        'sec27' => ['Were you at any time declared medically unfit; asked to submit your resignation; discharged or dismissed from Govt./PSU/Autonomous Body or Private Service?', true],
        'sec28' => ['Did you previously apply for any post in this Institute?', true],
        'sec29' => ['Have you routed your application through proper channel?', null],
        'sec30' => ['Have you enclosed duly certified copies of ACRs/APARs for the last five years (If applicable)?', false],
    ];

    public function key(): string
    {
        return 'declarations';
    }

    public function title(): string
    {
        return 'Additional Declarations';
    }

    public function formReference(): string
    {
        return '26–30';
    }

    public function rules(Application $application): array
    {
        $rules = [];
        foreach (self::QUESTIONS as $code => [, $detailsWhen]) {
            $rules["$code.answer"] = ['required', 'boolean'];
            $rules["$code.details"] = $detailsWhen === null
                ? ['nullable', 'string', 'max:5000']
                : [($detailsWhen ? 'required_if_accepted' : 'required_if_declined').":$code.answer", 'nullable', 'string', 'max:5000'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'sec26a.details.required_if_accepted' => 'Give details of the case(s).',
            'sec26b.details.required_if_accepted' => 'Give details of the pending case(s).',
            'sec27.details.required_if_accepted' => 'Give details.',
            'sec28.details.required_if_accepted' => 'Give particulars of your previous application.',
            'sec30.details.required_if_declined' => 'Give the reason for not enclosing the ACRs/APARs.',
        ];
    }

    public function save(Application $application, array $data): void
    {
        foreach (self::QUESTIONS as $code => [, $detailsWhen]) {
            $answer = (bool) $data[$code]['answer'];
            $keepDetails = $detailsWhen === null || $answer === $detailsWhen;
            $this->saveDeclaration($application, $code, $answer, $keepDetails ? ($data[$code]['details'] ?? null) : null);
        }
    }

    public function data(Application $application): array
    {
        return collect(self::QUESTIONS)->mapWithKeys(fn ($q, $code) => [$code => $this->declarationData($application, $code)])->all();
    }

    public function issues(Application $application): array
    {
        $unanswered = collect(array_keys(self::QUESTIONS))
            ->filter(fn ($code) => $application->declaration($code)?->answer === null);
        $issues = $unanswered->isNotEmpty() ? ['Answer every question in Sections 26 to 30.'] : [];

        return array_merge($issues, $this->missingDocumentIssues($application));
    }
}
