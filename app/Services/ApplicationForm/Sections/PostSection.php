<?php

namespace App\Services\ApplicationForm\Sections;

use App\Models\Application;
use Illuminate\Validation\Rule;

/** Sections 2–4 — Advertisement number, date and post applied for. */
class PostSection extends Section
{
    public function key(): string
    {
        return 'post';
    }

    public function title(): string
    {
        return 'Advertisement and Post';
    }

    public function formReference(): string
    {
        return '2–4';
    }

    public function rules(Application $application): array
    {
        return [
            'post_id' => [
                'required', 'integer',
                Rule::exists('posts', 'id')
                    ->where('advertisement_id', $application->advertisement_id)
                    ->where('status', 'open')
                    ->whereNull('deleted_at'),
                Rule::unique('applications', 'post_id')
                    ->where('user_id', $application->user_id)
                    ->ignore($application->id),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'post_id.exists' => 'This post is not open under the selected advertisement.',
            'post_id.unique' => 'You already have an application for this post.',
        ];
    }

    public function save(Application $application, array $data): void
    {
        // The post can change only while the application is still a first draft.
        if ($application->status === Application::DRAFT) {
            $application->forceFill(['post_id' => $data['post_id']])->save();
            $application->unsetRelation('post');
        }
    }

    public function data(Application $application): array
    {
        return [
            'advertisement_number' => $application->advertisement->number,
            'application_date' => ($application->application_date ?? now())->toDateString(),
            'post_id' => $application->post_id,
            'post_name' => $application->post->name,
        ];
    }

    public function issues(Application $application): array
    {
        if ($application->status === Application::DRAFT && $application->post->status !== 'open') {
            return ['The post you selected is no longer open for applications.'];
        }

        return [];
    }
}
