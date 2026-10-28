<?php

namespace App\Http\Controllers;

use App\Models\Advertisement;
use App\Models\AdvertisementDocument;
use App\Services\DocumentService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;

class PublicController extends Controller
{
    public function settings(SettingsService $settings): JsonResponse
    {
        return response()->json(collect($settings->all())->only([
            'institute.name', 'institute.short_name', 'institute.address', 'institute.recruitment_email',
            'institute.recruitment_phone', 'privacy.notice',
        ]));
    }

    public function advertisements(): JsonResponse
    {
        $ads = Advertisement::visibleToPublic()
            ->with(['posts' => fn ($q) => $q->select('id', 'advertisement_id', 'code', 'name', 'department', 'pay_level', 'total_vacancies', 'status', 'sort_order'),
                'documents' => fn ($q) => $q->where('is_public', true)])
            ->orderByDesc('opens_at')
            ->get();

        return response()->json($ads->map(fn (Advertisement $a) => $this->summary($a)));
    }

    public function advertisement(Advertisement $advertisement): JsonResponse
    {
        abort_unless(in_array($advertisement->status, [Advertisement::PUBLISHED, Advertisement::CLOSED], true), 404);
        $advertisement->load(['posts.vacancies', 'posts.eligibilityCriteria', 'documents' => fn ($q) => $q->where('is_public', true), 'cycle']);
        $advertisement->posts->each->setRelation('advertisement', $advertisement); // effectiveFeeSchedule() reads it

        return response()->json([
            ...$this->summary($advertisement),
            'instructions' => $advertisement->instructions,
            'reservation_details' => $advertisement->reservation_details,
            'age_relaxation_rules' => $advertisement->age_relaxation_rules,
            'fee_rules_text' => $advertisement->fee_rules_text,
            'payment_instructions' => $advertisement->payment_instructions,
            'endorsement_mode' => $advertisement->endorsement_mode,
            'endorsement_instructions' => $advertisement->endorsement_instructions,
            'referees_required' => $advertisement->referees_required,
            'cycle' => $advertisement->cycle?->name,
            'posts' => $advertisement->posts->map(fn ($p) => [
                ...$p->only(['id', 'code', 'name', 'department', 'group_classification', 'pay_level', 'pay_scale', 'total_vacancies',
                    'age_min', 'age_max', 'age_relaxation', 'essential_qualifications', 'desirable_qualifications',
                    'experience_requirements', 'other_conditions', 'status']),
                'age_cutoff_date' => $p->age_cutoff_date?->toDateString(),
                'vacancies' => $p->vacancies->map->only(['category', 'count', 'remarks']),
                'fee' => $p->effectiveFeeSchedule(),
                'accepting' => $p->status === 'open' && $advertisement->isOpen(),
            ]),
        ]);
    }

    public function document(Advertisement $advertisement, AdvertisementDocument $document, DocumentService $documents)
    {
        abort_unless($document->advertisement_id === $advertisement->id && $document->is_public, 404);
        abort_unless(in_array($advertisement->status, [Advertisement::PUBLISHED, Advertisement::CLOSED, Advertisement::ARCHIVED], true), 404);

        return $documents->stream($document->disk, $document->path, $document->original_name, $document->mime, true);
    }

    private function summary(Advertisement $a): array
    {
        return [
            'id' => $a->id,
            'number' => $a->number,
            'title' => $a->title,
            'department' => $a->department,
            'summary' => $a->summary,
            'publication_date' => $a->publication_date?->toDateString(),
            'opens_at' => $a->opens_at->toIso8601String(),
            'closes_at' => $a->closes_at->toIso8601String(),
            'status' => $a->status,
            'is_open' => $a->isOpen(),
            'contact_email' => $a->contact_email,
            'posts' => $a->posts->map->only(['id', 'code', 'name', 'department', 'pay_level', 'total_vacancies', 'status'])->values(),
            'documents' => $a->documents->map->only(['id', 'title', 'original_name', 'size'])->values(),
        ];
    }
}
