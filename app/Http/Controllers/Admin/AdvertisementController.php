<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdvertisementRequest;
use App\Http\Requests\PostRequest;
use App\Models\Advertisement;
use App\Models\AdvertisementDocument;
use App\Models\Application;
use App\Models\Post;
use App\Models\Role;
use App\Models\User;
use App\Services\AdvertisementService;
use App\Services\ApplicationAccess;
use App\Services\AuditLogger;
use App\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdvertisementController extends Controller
{
    public function __construct(
        private AdvertisementService $service,
        private ApplicationAccess $access,
        private AuditLogger $audit,
    ) {}

    private function assertManages(Request $request, Advertisement $ad): void
    {
        abort_unless($request->user()->hasPermission('advertisements.manage')
            && $this->access->managesAdvertisement($request->user(), $ad->id), 403);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $ads = Advertisement::with('cycle:id,name')
            ->withCount(['posts', 'applications as submitted_count' => fn ($q) => $q->whereNotNull('submitted_at')->where('status', '!=', Application::WITHDRAWN),
                'applications as draft_count' => fn ($q) => $q->where('status', Application::DRAFT)])
            ->when(! $user->isSuperAdmin(), fn ($q) => $this->scopeToUser($q, $user))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->cycle_id, fn ($q, $c) => $q->where('recruitment_cycle_id', $c))
            ->orderByDesc('opens_at')
            ->paginate((int) $request->input('per_page', 25));

        return response()->json($ads);
    }

    public function options(Request $request): JsonResponse
    {
        // Lightweight list for filters and pickers.
        $user = $request->user();

        return response()->json(Advertisement::with(['posts:id,advertisement_id,code,name,department'])
            ->when(! $user->isSuperAdmin(), fn ($q) => $this->scopeToUser($q, $user))
            ->orderByDesc('opens_at')->get(['id', 'number', 'title', 'status', 'recruitment_cycle_id']));
    }

    public function store(AdvertisementRequest $request): JsonResponse
    {
        $ad = $this->service->create($request->validated(), $request->user());

        return response()->json($this->detail($ad), 201);
    }

    public function show(Request $request, Advertisement $advertisement): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin() || $this->access->managesAdvertisement($request->user(), $advertisement->id), 403);

        return response()->json($this->detail($advertisement));
    }

    public function update(AdvertisementRequest $request, Advertisement $advertisement): JsonResponse
    {
        $this->assertManages($request, $advertisement);
        $this->service->update($advertisement, $request->validated(), $request->user());

        return response()->json($this->detail($advertisement->fresh()));
    }

    public function transition(Request $request, Advertisement $advertisement): JsonResponse
    {
        $this->assertManages($request, $advertisement);
        $data = $request->validate([
            'status' => ['required', Rule::in(Advertisement::STATUSES)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $this->service->transition($advertisement, $data['status'], $request->user(), $data['reason'] ?? null);

        return response()->json($this->detail($advertisement->fresh()));
    }

    public function destroy(Request $request, Advertisement $advertisement): JsonResponse
    {
        $this->assertManages($request, $advertisement);
        abort_unless($advertisement->status === Advertisement::DRAFT && ! $advertisement->applications()->exists(), 422,
            'Only a draft advertisement with no applications can be deleted. Archive it instead.');
        $this->audit->record('advertisement.deleted', $advertisement, ['number' => $advertisement->number]);
        $advertisement->delete();

        return response()->json(['deleted' => true]);
    }

    public function setAdministrators(Request $request, Advertisement $advertisement): JsonResponse
    {
        abort_unless($request->user()->hasPermission('users.manage'), 403);
        $data = $request->validate(['user_ids' => ['present', 'array'], 'user_ids.*' => ['integer', 'exists:users,id']]);
        $valid = User::whereIn('id', $data['user_ids'])->whereHas('roles', fn ($q) => $q->where('slug', Role::RECRUITMENT_ADMIN))->pluck('id');
        $old = $advertisement->administrators()->pluck('users.id');
        $advertisement->administrators()->sync($valid);
        $this->audit->record('advertisement.administrators_changed', $advertisement, ['user_ids' => $old], ['user_ids' => $valid]);

        return response()->json($this->detail($advertisement));
    }

    public function uploadDocument(Request $request, Advertisement $advertisement, DocumentService $documents): JsonResponse
    {
        $this->assertManages($request, $advertisement);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'file' => ['required', 'file', 'mimetypes:application/pdf', 'max:20480'],
            'is_public' => ['boolean'],
        ]);
        $file = $request->file('file');
        abort_unless(str_starts_with((string) file_get_contents($file->getRealPath(), false, null, 0, 5), '%PDF-'), 422, 'Upload a PDF document.');
        $path = 'advertisements/'.$advertisement->id.'/'.Str::random(40).'.pdf';
        Storage::disk($documents->disk())->put($path, file_get_contents($file->getRealPath()));

        $doc = $advertisement->documents()->create([
            'title' => $data['title'],
            'original_name' => Str::of($file->getClientOriginalName())->ascii()->replaceMatches('/[^A-Za-z0-9._ -]/', '')->limit(150, '')->toString() ?: 'advertisement.pdf',
            'disk' => $documents->disk(), 'path' => $path, 'mime' => 'application/pdf',
            'size' => $file->getSize(), 'is_public' => $data['is_public'] ?? true, 'uploaded_by' => $request->user()->id,
        ]);
        $this->audit->record('advertisement.document_uploaded', $advertisement, [], ['title' => $doc->title]);

        return response()->json($doc, 201);
    }

    public function downloadDocument(Request $request, Advertisement $advertisement, AdvertisementDocument $document, DocumentService $documents)
    {
        abort_unless($request->user()->isSuperAdmin() || $this->access->managesAdvertisement($request->user(), $advertisement->id), 403);
        abort_unless($document->advertisement_id === $advertisement->id, 404);

        return $documents->stream($document->disk, $document->path, $document->original_name, $document->mime, $request->boolean('inline'));
    }

    public function deleteDocument(Request $request, Advertisement $advertisement, AdvertisementDocument $document): JsonResponse
    {
        $this->assertManages($request, $advertisement);
        abort_unless($document->advertisement_id === $advertisement->id, 404);
        Storage::disk($document->disk)->delete($document->path);
        $this->audit->record('advertisement.document_deleted', $advertisement, ['title' => $document->title]);
        $document->delete();

        return response()->json(['deleted' => true]);
    }

    public function storePost(PostRequest $request, Advertisement $advertisement): JsonResponse
    {
        $this->assertManages($request, $advertisement);

        return response()->json($this->service->savePost($advertisement, $request->validated(), null, $request->user()), 201);
    }

    public function updatePost(PostRequest $request, Advertisement $advertisement, Post $post): JsonResponse
    {
        $this->assertManages($request, $advertisement);
        abort_unless($post->advertisement_id === $advertisement->id, 404);

        return response()->json($this->service->savePost($advertisement, $request->validated(), $post, $request->user()));
    }

    public function destroyPost(Request $request, Advertisement $advertisement, Post $post): JsonResponse
    {
        $this->assertManages($request, $advertisement);
        abort_unless($post->advertisement_id === $advertisement->id, 404);
        abort_if($post->applications()->exists(), 422, 'This post has applications. Close it instead of deleting it.');
        $this->audit->record('post.deleted', $post, ['code' => $post->code]);
        $post->delete();

        return response()->json(['deleted' => true]);
    }

    private function scopeToUser($query, User $user)
    {
        return $query->where(fn ($q) => $q->whereHas('administrators', fn ($a) => $a->where('users.id', $user->id))
            ->orWhereHas('scrutinyTeams.members', fn ($m) => $m->where('users.id', $user->id))
            ->orWhereHas('committees.members', fn ($m) => $m->where('users.id', $user->id)));
    }

    private function detail(Advertisement $ad): array
    {
        $ad->load(['cycle', 'posts.vacancies', 'posts.eligibilityCriteria', 'documents', 'scrutinyStages',
            'administrators:id,name,email', 'documentRequirements.documentType', 'documentRequirements.post:id,code',
            'checklistAssignments.template:id,name', 'checklistAssignments.post:id,code', 'scrutinyTeams.members:id,name', 'committees.members:id,name']);

        return [
            ...$ad->toArray(),
            'opens_at' => $ad->opens_at->format('Y-m-d\TH:i'),
            'closes_at' => $ad->closes_at->format('Y-m-d\TH:i'),
            'publication_date' => $ad->publication_date?->toDateString(),
            'publish_problems' => $ad->status === Advertisement::DRAFT ? $this->service->publishProblems($ad) : [],
            'allowed_transitions' => Advertisement::TRANSITIONS[$ad->status] ?? [],
            'application_counts' => Application::where('advertisement_id', $ad->id)->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status'),
        ];
    }
}
