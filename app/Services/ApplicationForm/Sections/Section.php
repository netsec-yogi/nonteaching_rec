<?php

namespace App\Services\ApplicationForm\Sections;

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Services\RequirementResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One step of the application wizard, corresponding to one or more numbered
 * sections of the paper form. A section owns three things:
 *
 *   rules()  — validation for a save of this step (drafts may be partial
 *              only where a field is genuinely optional on the paper form)
 *   save()   — persisting validated data
 *   issues() — what still blocks final submission, in plain language
 *
 * Document uploads are separate requests; a section reports missing mandatory
 * documents whose document type is configured to appear on its step.
 */
abstract class Section
{
    public function __construct(protected RequirementResolver $requirements) {}

    abstract public function key(): string;

    abstract public function title(): string;

    /** The paper form's numbering, e.g. "18" or "26–30". */
    abstract public function formReference(): string;

    /** @return array<string, mixed> */
    abstract public function rules(Application $application): array;

    abstract public function save(Application $application, array $data): void;

    /** Current data in the shape the wizard posts back. */
    abstract public function data(Application $application): array;

    /** @return list<string> */
    public function issues(Application $application): array
    {
        return $this->missingDocumentIssues($application);
    }

    public function messages(): array
    {
        return [];
    }

    public function attributes(): array
    {
        return [];
    }

    public function isApplicable(Application $application): bool
    {
        return true;
    }

    /** Cross-field checks run after the rules pass. */
    public function after(Application $application): ?callable
    {
        return null;
    }

    /** @return list<string> */
    protected function missingDocumentIssues(Application $application): array
    {
        return $this->requirements->missingDocuments($application)
            ->filter(fn ($r) => $r->documentType->step === $this->key())
            ->map(fn ($r) => 'Upload required: '.$r->documentType->name.'.')
            ->values()
            ->all();
    }

    /**
     * Replace the rows of a repeatable section. Rows that carry an `id`
     * belonging to this application are updated in place, so documents
     * attached to them stay attached; rows not posted back are removed and
     * their documents detached (not deleted).
     */
    protected function syncRows(Application $application, HasMany $relation, array $rows, callable $map): void
    {
        $existing = $relation->get()->keyBy('id');
        $keep = [];

        foreach (array_values($rows) as $index => $row) {
            $attributes = $map($row) + ['sort_order' => $index];
            $id = $row['id'] ?? null;

            if ($id && $existing->has($id)) {
                $existing[$id]->fill($attributes)->save();
                $keep[] = (int) $id;
            } else {
                $keep[] = $relation->create($attributes)->id;
            }
        }

        $existing->except($keep)->each(function (Model $model) {
            ApplicationDocument::where('documentable_type', $model->getMorphClass())
                ->where('documentable_id', $model->getKey())
                ->update(['documentable_type' => null, 'documentable_id' => null]);
            $model->delete();
        });
    }

    protected function declarationData(Application $application, string $code): array
    {
        $d = $application->declaration($code);

        return ['answer' => $d?->answer, 'details' => $d?->details];
    }

    protected function saveDeclaration(Application $application, string $code, ?bool $answer, ?string $details): void
    {
        $application->declarations()->updateOrCreate(['code' => $code], ['answer' => $answer, 'details' => $details]);
    }

    protected function yesNoWithDetailsRules(string $prefix, bool $detailsWhenYes = true): array
    {
        $rule = $detailsWhenYes ? 'required_if_accepted' : 'required_if_declined';

        return [
            "$prefix.answer" => ['required', 'boolean'],
            "$prefix.details" => ["$rule:$prefix.answer", 'nullable', 'string', 'max:5000'],
        ];
    }
}
