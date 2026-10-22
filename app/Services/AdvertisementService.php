<?php

namespace App\Services;

use App\Models\Advertisement;
use App\Models\Post;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdvertisementService
{
    /** Stages created with every new advertisement; administrators can change them. */
    public const DEFAULT_STAGES = [
        ['sequence' => 1, 'code' => 'initial_scrutiny', 'name' => 'Initial scrutiny', 'role' => Role::SCRUTINY_USER, 'is_decision_stage' => false, 'allow_same_reviewer' => false],
        ['sequence' => 2, 'code' => 'review', 'name' => 'Review by authorised officer', 'role' => Role::RECRUITMENT_ADMIN, 'is_decision_stage' => true, 'allow_same_reviewer' => false],
    ];

    public function __construct(private AuditLogger $audit) {}

    public function create(array $data, User $actor): Advertisement
    {
        return DB::transaction(function () use ($data, $actor) {
            $ad = Advertisement::create($data + ['created_by' => $actor->id, 'status' => Advertisement::DRAFT]);
            foreach (self::DEFAULT_STAGES as $stage) {
                $ad->scrutinyStages()->create($stage);
            }
            $this->audit->record('advertisement.created', $ad, [], ['number' => $ad->number], user: $actor);

            return $ad;
        });
    }

    public function update(Advertisement $ad, array $data, User $actor): Advertisement
    {
        if ($ad->status === Advertisement::ARCHIVED) {
            throw ValidationException::withMessages(['status' => 'An archived advertisement cannot be edited.']);
        }
        if ($ad->applications()->whereNotNull('submitted_at')->exists()) {
            // Once applications are in, changing these would alter what candidates applied under.
            foreach (['number', 'reference_prefix', 'referees_required'] as $locked) {
                if (array_key_exists($locked, $data) && (string) $data[$locked] !== (string) $ad->$locked) {
                    throw ValidationException::withMessages([$locked => 'This cannot be changed after applications have been submitted.']);
                }
            }
        }
        $ad->fill($data);
        $this->audit->recordChanges('advertisement.updated', $ad);
        $ad->save();

        return $ad;
    }

    public function transition(Advertisement $ad, string $to, User $actor, ?string $reason = null): Advertisement
    {
        if (! in_array($to, Advertisement::TRANSITIONS[$ad->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => "An advertisement cannot move from {$ad->status} to {$to}."]);
        }

        if ($to === Advertisement::PUBLISHED) {
            $problems = $this->publishProblems($ad);
            if ($problems) {
                throw ValidationException::withMessages(['status' => $problems]);
            }
        }

        $from = $ad->status;
        $ad->forceFill([
            'status' => $to,
            'published_at' => $to === Advertisement::PUBLISHED ? ($ad->published_at ?? now()) : $ad->published_at,
            'archived_at' => $to === Advertisement::ARCHIVED ? now() : null,
        ])->save();
        $this->audit->record('advertisement.status_changed', $ad, ['status' => $from], ['status' => $to], ['reason' => $reason], user: $actor);

        return $ad;
    }

    /** @return list<string> */
    public function publishProblems(Advertisement $ad): array
    {
        $ad->loadMissing(['posts', 'scrutinyStages']);
        $problems = [];
        if ($ad->posts->isEmpty()) {
            $problems[] = 'Add at least one post.';
        }
        if ($ad->closes_at->lte($ad->opens_at)) {
            $problems[] = 'The closing date must be after the opening date.';
        }
        if ($ad->scrutinyStages->where('is_decision_stage', true)->isEmpty()) {
            $problems[] = 'Configure a scrutiny workflow with a decision stage.';
        }
        if ($ad->referees_required > 0 && ! $ad->referee_form_template_id) {
            $problems[] = 'Choose the referee recommendation form.';
        }
        if (empty($ad->payment_methods)) {
            $problems[] = 'Select at least one accepted payment method.';
        }
        if (! $ad->checklistAssignments()->exists()) {
            $problems[] = 'Assign a checklist template to the advertisement or its posts.';
        }

        return $problems;
    }

    public function savePost(Advertisement $ad, array $data, ?Post $post, User $actor): Post
    {
        return DB::transaction(function () use ($ad, $data, $post, $actor) {
            $vacancies = $data['vacancies'] ?? [];
            $criteria = $data['criteria'] ?? [];
            unset($data['vacancies'], $data['criteria']);

            $post ??= new Post(['advertisement_id' => $ad->id]);
            $old = $post->exists ? $post->getAttributes() : [];
            $post->fill($data)->save();

            $post->vacancies()->delete();
            foreach ($vacancies as $v) {
                $post->vacancies()->create(['category' => $v['category'], 'count' => $v['count'], 'remarks' => $v['remarks'] ?? null]);
            }
            $post->eligibilityCriteria()->delete();
            foreach (array_values($criteria) as $i => $c) {
                $post->eligibilityCriteria()->create([
                    'type' => $c['type'], 'label' => $c['label'], 'value' => $c['value'] ?? null,
                    'qualification_level' => $c['qualification_level'] ?? null, 'is_essential' => (bool) ($c['is_essential'] ?? true),
                    'description' => $c['description'] ?? null, 'sort_order' => $i,
                ]);
            }
            $this->audit->record($old ? 'post.updated' : 'post.created', $post, array_intersect_key($old, $data), $data,
                ['vacancies' => $vacancies, 'criteria_count' => count($criteria)], user: $actor);

            return $post->load(['vacancies', 'eligibilityCriteria']);
        });
    }
}
