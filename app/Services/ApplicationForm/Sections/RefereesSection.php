<?php

namespace App\Services\ApplicationForm\Sections;

use App\Models\Application;
use App\Models\Referee;
use Illuminate\Validation\Validator;

/** Section 25 — Referees. */
class RefereesSection extends Section
{
    private const FIELDS = ['name', 'designation', 'organization', 'address', 'phone', 'email', 'relationship', 'years_known', 'is_resident_of_india', 'is_not_relative', 'is_employer_or_superior'];

    public function key(): string
    {
        return 'referees';
    }

    public function title(): string
    {
        return 'Referees';
    }

    public function formReference(): string
    {
        return '25';
    }

    public function isApplicable(Application $application): bool
    {
        return $application->advertisement->referees_required > 0;
    }

    public function rules(Application $application): array
    {
        if (! $this->isApplicable($application)) {
            return [];
        }
        $count = $application->advertisement->referees_required;

        return [
            'referees' => ['required', 'array', "size:$count"],
            'referees.*.name' => ['required', 'string', 'max:200'],
            'referees.*.designation' => ['required', 'string', 'max:200'],
            'referees.*.organization' => ['required', 'string', 'max:250'],
            'referees.*.address' => ['required', 'string', 'max:1000'],
            'referees.*.phone' => ['required', 'regex:/^\+?[0-9][0-9 -]{7,18}$/'],
            'referees.*.email' => ['required', 'email:rfc', 'max:255'],
            'referees.*.relationship' => ['required', 'string', 'max:200'],
            'referees.*.years_known' => ['required', 'integer', 'between:0,80'],
            'referees.*.is_resident_of_india' => ['accepted'],
            'referees.*.is_not_relative' => ['accepted'],
            'referees.*.is_employer_or_superior' => ['boolean'],
            'confirm_accuracy' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'referees.size' => 'Provide details of exactly :size referees.',
            'referees.*.is_resident_of_india.accepted' => 'Referees should be persons resident in India.',
            'referees.*.is_not_relative.accepted' => 'Referees must not be your relations.',
            'referees.*.phone.regex' => 'Enter a valid phone number.',
            'confirm_accuracy.accepted' => 'Confirm that the referee details are accurate.',
        ];
    }

    public function attributes(): array
    {
        return [
            'referees.*.name' => 'referee name',
            'referees.*.email' => 'referee email',
            'referees.*.years_known' => 'years known',
        ];
    }

    /** Distinct referee emails, none equal to the applicant's own. */
    public function after(Application $application): callable
    {
        return function (Validator $validator) use ($application) {
            $emails = collect($validator->getData()['referees'] ?? [])->pluck('email')->map(fn ($e) => mb_strtolower(trim((string) $e)));
            $own = collect([$application->user->email, $application->personal?->email])->filter()->map(fn ($e) => mb_strtolower($e));

            foreach ($emails as $i => $email) {
                if ($email === '') {
                    continue;
                }
                if ($own->contains($email)) {
                    $validator->errors()->add("referees.$i.email", 'You cannot nominate yourself as a referee.');
                }
                if (! $application->referee_email_exception && $emails->search($email) !== $i) {
                    $validator->errors()->add("referees.$i.email", 'Each referee must have a different email address.');
                }
            }
        };
    }

    public function save(Application $application, array $data): void
    {
        if (! $this->isApplicable($application)) {
            return;
        }
        foreach (array_values($data['referees']) as $i => $row) {
            $attributes = array_intersect_key($row, array_flip(self::FIELDS));
            $attributes['email'] = mb_strtolower(trim($attributes['email']));
            $attributes['is_employer_or_superior'] = (bool) ($attributes['is_employer_or_superior'] ?? false);
            $application->referees()->updateOrCreate(['position' => $i + 1], $attributes);
        }
        $application->referees()->where('position', '>', count($data['referees']))
            ->whereDoesntHave('invitations')->delete();

        $application->forceFill(['referees_confirmed_at' => now()])->save();
    }

    public function data(Application $application): array
    {
        $count = $application->advertisement->referees_required;
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $r = $application->referees->firstWhere('position', $i);
            $rows[] = $r ? ['position' => $i, ...$r->only(self::FIELDS)] : [
                'position' => $i, 'name' => '', 'designation' => '', 'organization' => '', 'address' => '',
                'phone' => '', 'email' => '', 'relationship' => '', 'years_known' => null,
                'is_resident_of_india' => false, 'is_not_relative' => false, 'is_employer_or_superior' => false,
            ];
        }

        return [
            'referees' => $rows,
            'confirm_accuracy' => $application->referees_confirmed_at !== null,
            'required_count' => $count,
            'email_exception' => $application->referee_email_exception,
        ];
    }

    public function issues(Application $application): array
    {
        $issues = [];
        $count = $application->advertisement->referees_required;
        if ($application->referees->count() < $count) {
            $issues[] = "Provide details of {$count} referees (Section 25).";
        }
        if (! $application->referees_confirmed_at) {
            $issues[] = 'Confirm that the referee details are accurate.';
        }

        // Paper item 25: where the candidate has been in employment, the present or most recent
        // employer or immediate superior should be a referee, or a testimonial from them produced.
        if (($application->is_in_service || $application->employments->isNotEmpty())
            && $application->referees->isNotEmpty()
            && ! $application->referees->contains('is_employer_or_superior', true)
            && ! $application->documents->contains(fn ($d) => $d->documentType?->code === 'employer_testimonial')) {
            $issues[] = 'As you are or have been in employment, give your present or most recent employer or immediate superior as a referee, or upload a testimonial from them.';
        }

        return array_merge($issues, $this->missingDocumentIssues($application));
    }
}
