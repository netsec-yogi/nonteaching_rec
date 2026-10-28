<?php

namespace App\Services\ApplicationForm\Sections;

use App\Models\Application;
use Illuminate\Validation\Rule;

/** Sections 5–16 — Personal information and addresses. */
class PersonalSection extends Section
{
    private const FIELDS = [
        'salutation', 'full_name', 'relation_type', 'relation_name', 'date_of_birth', 'place_of_birth', 'mobile',
        'email', 'nationality', 'gender', 'marital_status', 'category', 'is_pwd', 'disability_type',
        'disability_percentage', 'nearest_railway_station', 'permanent_same_as_correspondence',
    ];

    private const ADDRESS_FIELDS = ['line1', 'line2', 'city', 'district', 'state', 'pincode', 'country'];

    public function key(): string
    {
        return 'personal';
    }

    public function title(): string
    {
        return 'Personal Information';
    }

    public function formReference(): string
    {
        return '5–16';
    }

    public function rules(Application $application): array
    {
        $rules = [
            'salutation' => ['nullable', Rule::in(config('recruitment.salutations'))],
            'full_name' => ['required', 'string', 'max:200', 'regex:/^[\p{L}\p{M} .\'-]+$/u'],
            'relation_type' => ['required', Rule::in(['father', 'husband'])],
            'relation_name' => ['required', 'string', 'max:200'],
            'date_of_birth' => ['required', 'date', 'before:-14 years', 'after:-100 years'],
            'place_of_birth' => ['required', 'string', 'max:150'],
            'mobile' => ['required', 'regex:/^(\+91[- ]?)?[6-9]\d{9}$/'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'nationality' => ['required', 'string', 'max:60'],
            'gender' => ['required', Rule::in(array_keys(config('recruitment.genders')))],
            'marital_status' => ['required', Rule::in(array_keys(config('recruitment.marital_statuses')))],
            'category' => ['required', Rule::in(array_keys(config('recruitment.categories')))],
            'is_pwd' => ['required', 'boolean'],
            'disability_type' => ['required_if_accepted:is_pwd', 'nullable', 'string', 'max:150'],
            'disability_percentage' => ['nullable', 'integer', 'between:1,100'],
            'nearest_railway_station' => ['required', 'string', 'max:150'],
            'permanent_same_as_correspondence' => ['boolean'],
        ];

        foreach (['correspondence', 'permanent'] as $type) {
            $required = $type === 'permanent' ? 'exclude_if:permanent_same_as_correspondence,true' : 'present';
            $rules["$type"] = [$required, 'array'];
            $rules["$type.line1"] = [$required, 'required', 'string', 'max:255'];
            $rules["$type.line2"] = [$required, 'nullable', 'string', 'max:255'];
            $rules["$type.city"] = [$required, 'required', 'string', 'max:100'];
            $rules["$type.district"] = [$required, 'nullable', 'string', 'max:100'];
            $rules["$type.state"] = [$required, 'required', 'string', 'max:100'];
            $rules["$type.pincode"] = [$required, 'required', 'regex:/^[1-9][0-9]{5}$/'];
            $rules["$type.country"] = [$required, 'nullable', 'string', 'max:60'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'full_name.regex' => 'Write your name in letters only, as it appears in your certificates.',
            'date_of_birth.before' => 'Check the date of birth.',
            'mobile.regex' => 'Enter a valid 10-digit Indian mobile number.',
            'correspondence.pincode.regex' => 'Enter a valid 6-digit PIN code.',
            'permanent.pincode.regex' => 'Enter a valid 6-digit PIN code.',
        ];
    }

    public function attributes(): array
    {
        return [
            'relation_name' => "father's / husband's name",
            'correspondence.line1' => 'correspondence address',
            'correspondence.city' => 'city', 'correspondence.state' => 'state', 'correspondence.pincode' => 'PIN code',
            'permanent.line1' => 'permanent address',
            'permanent.city' => 'city', 'permanent.state' => 'state', 'permanent.pincode' => 'PIN code',
        ];
    }

    public function save(Application $application, array $data): void
    {
        $personal = array_intersect_key($data, array_flip(self::FIELDS));
        $personal['full_name'] = mb_strtoupper(trim($personal['full_name']));
        if (! $personal['is_pwd']) {
            $personal['disability_type'] = null;
            $personal['disability_percentage'] = null;
        }

        $application->personal()->updateOrCreate([], $personal);

        $correspondence = array_intersect_key($data['correspondence'], array_flip(self::ADDRESS_FIELDS));
        $permanent = ($data['permanent_same_as_correspondence'] ?? false)
            ? $correspondence
            : array_intersect_key($data['permanent'], array_flip(self::ADDRESS_FIELDS));

        $application->addresses()->updateOrCreate(['type' => 'correspondence'], $correspondence);
        $application->addresses()->updateOrCreate(['type' => 'permanent'], $permanent);
    }

    public function data(Application $application): array
    {
        $p = $application->personal;
        $user = $application->user;
        $data = [];
        foreach (self::FIELDS as $field) {
            $data[$field] = $p?->$field;
        }
        $data['date_of_birth'] = $p?->date_of_birth?->toDateString();
        $data['full_name'] ??= $user ? mb_strtoupper($user->name) : null;
        $data['email'] ??= $user?->email;
        $data['mobile'] ??= $user?->mobile;
        $data['nationality'] ??= 'Indian';
        $data['permanent_same_as_correspondence'] = (bool) $p?->permanent_same_as_correspondence;

        foreach (['correspondence', 'permanent'] as $type) {
            $address = $application->address($type);
            $data[$type] = collect(self::ADDRESS_FIELDS)->mapWithKeys(fn ($f) => [$f => $address?->$f])->all();
            $data[$type]['country'] ??= 'India';
        }

        return $data;
    }

    public function issues(Application $application): array
    {
        $issues = [];
        $p = $application->personal;
        if (! $p || ! $p->full_name || ! $p->date_of_birth || ! $p->category || $p->is_pwd === null) {
            $issues[] = 'Complete your personal information.';
        }
        if (! $application->address('correspondence')?->line1) {
            $issues[] = 'Enter your correspondence address.';
        }

        return array_merge($issues, $this->missingDocumentIssues($application));
    }
}
