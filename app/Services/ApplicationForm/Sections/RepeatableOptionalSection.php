<?php

namespace App\Services\ApplicationForm\Sections;

use App\Models\Application;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A yes/no question followed by repeatable rows when the answer is yes
 * (Sections 21, 22 and 23 share this shape).
 */
abstract class RepeatableOptionalSection extends Section
{
    /** Column on applications holding the yes/no answer. */
    abstract protected function flag(): string;

    abstract protected function relation(Application $application): HasMany;

    /** @return array<string, array> row field => rules */
    abstract protected function rowRules(): array;

    abstract protected function question(): string;

    /** Date fields formatted as Y-m-d in data(). */
    protected function dateFields(): array
    {
        return [];
    }

    public function rules(Application $application): array
    {
        $rules = [
            $this->flag() => ['required', 'boolean'],
            'rows' => ['exclude_unless:'.$this->flag().',true', 'required', 'array', 'min:1', 'max:50'],
            'rows.*.id' => ['nullable', 'integer'],
        ];
        foreach ($this->rowRules() as $field => $fieldRules) {
            $rules["rows.*.$field"] = $fieldRules;
        }

        return $rules;
    }

    public function save(Application $application, array $data): void
    {
        $yes = (bool) $data[$this->flag()];
        $application->forceFill([$this->flag() => $yes])->save();
        $fields = array_keys($this->rowRules());

        $this->syncRows($application, $this->relation($application), $yes ? $data['rows'] : [],
            fn (array $row) => array_intersect_key($row, array_flip($fields)) + array_fill_keys($fields, null));
    }

    public function data(Application $application): array
    {
        $fields = array_keys($this->rowRules());

        return [
            $this->flag() => $application->{$this->flag()},
            'rows' => $this->relation($application)->get()->map(function ($row) use ($fields) {
                $out = ['id' => $row->id] + $row->only($fields);
                foreach ($this->dateFields() as $f) {
                    $out[$f] = $row->$f?->toDateString();
                }

                return $out;
            })->all(),
        ];
    }

    public function issues(Application $application): array
    {
        $issues = $application->{$this->flag()} === null ? [$this->question()] : [];

        return array_merge($issues, $this->missingDocumentIssues($application));
    }
}
